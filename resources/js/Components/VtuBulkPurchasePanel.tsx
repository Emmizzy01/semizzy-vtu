import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { AlertCircle, LoaderCircle, RefreshCw } from 'lucide-react';

type Product = {
  code: string; service_type: 'airtime' | 'data'; network: string; name: string;
  price_minor: number; currency: string; is_active: boolean; metadata?: Record<string, unknown>;
};
type Batch = {
  id: string; status: string; item_count: number; total_amount_minor: number; currency: string;
  items: { item: number; service_type: string; recipient: string; status: string; order_public_id: string | null; message: string | null }[];
};

function money(minor: number) {
  return new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 2 }).format(minor / 100);
}
const BULK_STORAGE_KEY = 'semizzy-vtu-bulk-pending';
type StoredBulkRequest = { batchKey: string; serviceType: 'airtime' | 'data'; productCode: string; recipientsText: string; amount: string };
function readStoredBulkRequest(): StoredBulkRequest | null {
  try {
    if (typeof window === 'undefined') return null;
    const raw = window.sessionStorage.getItem(BULK_STORAGE_KEY);
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<StoredBulkRequest>;
    if (!value || typeof value.batchKey !== 'string' || !['airtime', 'data'].includes(value.serviceType ?? '')
      || typeof value.productCode !== 'string' || typeof value.recipientsText !== 'string' || typeof value.amount !== 'string') return null;
    return value as StoredBulkRequest;
  } catch { return null; }
}
function requestKey() {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `bulk-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
const BULK_DRAFT_KEY = 'semizzy-vtu-bulk-draft';
type SavedBulkDraft = { batchKey: string; productCode: string; recipientsText: string; amount: string; publicId?: string };
function readSavedDraft(): SavedBulkDraft | null {
  try {
    const raw = window.sessionStorage.getItem(BULK_DRAFT_KEY);
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<SavedBulkDraft>;
    if (typeof value.batchKey !== 'string' || typeof value.productCode !== 'string'
      || typeof value.recipientsText !== 'string' || typeof value.amount !== 'string'
      || (value.publicId !== undefined && typeof value.publicId !== 'string')) return null;
    return value as SavedBulkDraft;
  } catch { return null; }
}
function saveDraft(value: SavedBulkDraft) {
  try { window.sessionStorage.setItem(BULK_DRAFT_KEY, JSON.stringify(value)); } catch { /* optional browser storage */ }
}

export default function VtuBulkPurchasePanel({
  serviceType, products, productCode, onProductCodeChange, onClose,
}: {
  serviceType: 'airtime' | 'data';
  products: Product[];
  productCode: string;
  onProductCodeChange: (code: string) => void;
  onClose: () => void;
}) {
  const [savedDraft] = useState(readSavedDraft);
  const [recipientsText, setRecipientsText] = useState(savedDraft?.recipientsText ?? '');
  const [amount, setAmount] = useState(savedDraft?.amount ?? '');
  const [pin, setPin] = useState('');
  const [batchKey, setBatchKey] = useState(savedDraft?.batchKey ?? requestKey);
  const [batch, setBatch] = useState<Batch | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const recipients = useMemo(() => recipientsText.split(/\r?\n/).map((v) => v.trim()).filter(Boolean), [recipientsText]);
  const product = products.find((item) => item.code === productCode && item.is_active);
  const perItemMinor = serviceType === 'airtime' ? Math.round(Number(amount || 0) * 100) : (product?.price_minor ?? 0);

  useEffect(() => {
    if (!restoredRequest || restoredRequest.serviceType !== serviceType) return;
    onProductCodeChange(restoredRequest.productCode);
    let cancelled = false;
    fetch(`/wallet/vtu/orders/bulk-recover?batch_key=${encodeURIComponent(restoredRequest.batchKey)}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).then(async (response) => {
      const body = await response.json().catch(() => ({}));
      if (response.ok && body.data && !cancelled) setBatch(body.data as Batch);
      else if (response.status !== 404 && !cancelled) setError(typeof body.message === 'string' ? body.message : 'Saved batch recovery is temporarily unavailable.');
    }).catch(() => { if (!cancelled) setError('Could not recover the previous batch yet. Keep the same batch key and original items.'); });
    return () => { cancelled = true; };
  }, [restoredRequest, serviceType]);

  const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

  useEffect(() => {
    if (!savedDraft) return;
    if (savedDraft.productCode) onProductCodeChange(savedDraft.productCode);
    let cancelled = false;
    async function recoverSavedBatch() {
      const url = savedDraft.publicId
        ? `/wallet/vtu/orders/bulk/${encodeURIComponent(savedDraft.publicId)}`
        : `/wallet/vtu/orders/bulk-recover?batch_key=${encodeURIComponent(savedDraft.batchKey)}`;
      try {
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) return;
        const body = await response.json().catch(() => ({}));
        if (!cancelled && body.data) {
          setBatch(body.data as Batch);
          saveDraft({ ...savedDraft, publicId: (body.data as Batch).id });
        }
      } catch { /* Keep the saved key and form so the user can safely check again. */ }
    }
    void recoverSavedBatch();
    return () => { cancelled = true; };
  }, [savedDraft, onProductCodeChange]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!product || recipients.length < 10 || recipients.length > 100) return;
    if (serviceType === 'airtime' && (!Number.isInteger(Number(amount)) || Number(amount) < 1 || Number(amount) > 1000000)) {
      setError('Enter a whole-naira airtime amount between ₦1 and ₦1,000,000.'); return;
    }
    if (!/^\d{4}$/.test(pin)) { setError('Enter your four-digit transaction PIN.'); return; }
    setBusy(true); setError('');
    // Save the stable batch key and exact request inputs before sending. If the
    // response is lost or the page reloads, recover the existing batch by key.
    saveDraft({ batchKey, productCode: product.code, recipientsText, amount });
    try {
      const response = await fetch('/wallet/vtu/orders/bulk', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({
          batch_key: batchKey, transaction_pin: pin,
          items: recipients.map((recipient) => ({ product_code: product.code, recipient, ...(serviceType === 'airtime' ? { amount: Number(amount) } : {}) })),
        }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok && response.status !== 202) throw new Error(typeof body.message === 'string' ? body.message : 'Bulk batch could not be accepted.');
      setBatch(body.data as Batch); setPin('');
      saveDraft({ batchKey, productCode: product.code, recipientsText, amount, publicId: (body.data as Batch).id });
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Bulk batch could not be accepted.'); }
    finally { setBusy(false); }
  }

  async function refresh() {
    if (!batch) return;
    try {
      window.sessionStorage.setItem(BULK_STORAGE_KEY, JSON.stringify({ batchKey, serviceType, productCode: product.code, recipientsText, amount }));
    } catch { /* recovery storage is best-effort; server idempotency remains authoritative */ }
    setBusy(true); setError('');
    try {
      const response = await fetch(`/wallet/vtu/orders/bulk/${encodeURIComponent(batch.id)}`, {
        credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(typeof body.message === 'string' ? body.message : 'Batch status could not be refreshed.');
      setBatch(body.data as Batch);
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'Batch status could not be refreshed.'); }
    finally { setBusy(false); }
  }

  return (
    <section className="space-y-5 rounded-2xl border border-[var(--line)] p-4 sm:p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div><h2 className="font-bold">Bulk purchase</h2><p className="mt-1 text-sm leading-5 text-[var(--muted)]">Prepare 10–100 recipients. Every item is processed and tracked as its own order.</p></div>
        <button type="button" onClick={onClose} disabled={busy} className="rounded-lg border border-[var(--line)] px-3 py-2 text-xs font-semibold">Return to single purchase</button>
      </div>
      {error && <div role="alert" className="flex gap-2 rounded-xl border border-rose-500/30 bg-rose-500/5 p-3 text-sm"><AlertCircle size={18} className="shrink-0 text-rose-500" />{error}</div>}
      {batch ? (
        <>
          <div className="rounded-xl bg-[var(--page)] p-4">
            <div className="flex flex-wrap items-center justify-between gap-3"><strong className="capitalize">{batch.status.replaceAll('_', ' ')}</strong><button type="button" onClick={refresh} disabled={busy} className="inline-flex items-center gap-2 rounded-lg border border-[var(--line)] px-3 py-2 text-xs font-semibold">{busy ? <LoaderCircle size={15} className="animate-spin" /> : <RefreshCw size={15} />} Refresh status</button></div>
            <p className="mt-2 text-sm text-[var(--muted)]">{batch.item_count} items · server-calculated total {money(batch.total_amount_minor)}</p><p className="mt-1 break-all text-xs text-[var(--muted)]">Batch reference: {batch.id}</p>
            {['queued', 'processing'].includes(batch.status) && <p className="mt-2 text-sm text-[var(--muted)]">Processing continues in the background. Refresh to see item outcomes; do not resubmit this batch.</p>}
            {batch.items.some((item) => item.status === 'unknown') && <p className="mt-2 text-sm text-amber-700">An item has an uncertain outcome. Reconcile its existing order before any retry.</p>}
          </div>
          <div className="max-h-80 space-y-2 overflow-auto rounded-xl border border-[var(--line)] p-3">
            {batch.items.map((item) => <div key={item.item} className="flex items-start justify-between gap-3 border-b border-[var(--line)] py-2 last:border-0"><div className="min-w-0"><p className="text-sm font-semibold">Item {item.item + 1} · {item.recipient}</p>{item.order_public_id && <p className="mt-1 break-all text-xs text-[var(--muted)]">Order: {item.order_public_id}</p>}{item.message && <p className="mt-1 text-xs text-amber-700">{item.message}</p>}</div><span className="shrink-0 text-xs font-bold capitalize">{item.status.replaceAll('_', ' ')}</span></div>)}
          </div>
          <button type="button" onClick={() => { try { window.sessionStorage.removeItem(BULK_DRAFT_KEY); } catch { /* optional browser storage */ } setBatch(null); setRecipientsText(''); setAmount(''); setBatchKey(requestKey()); setError(''); try { window.sessionStorage.removeItem(BULK_STORAGE_KEY); } catch { /* optional browser storage */ } }} className="w-full rounded-xl border border-[var(--line)] px-4 py-3 font-semibold">Prepare another batch</button>
        </>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          <label className="block text-sm font-semibold">Product option<select value={productCode} onChange={(event) => onProductCodeChange(event.target.value)} required disabled={busy} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal">{products.filter((item) => item.is_active).map((item) => <option key={item.code} value={item.code}>{item.network} · {item.name} · {serviceType === 'airtime' ? 'Enter amount' : money(item.price_minor)}</option>)}</select></label>
          {serviceType === 'airtime' && <label className="block text-sm font-semibold">Amount per recipient (₦)<input type="number" min="1" max="1000000" step="1" value={amount} onChange={(event) => setAmount(event.target.value)} required disabled={busy} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal" /></label>}
          <label className="block text-sm font-semibold">Recipients — one per line<textarea value={recipientsText} onChange={(event) => setRecipientsText(event.target.value)} rows={8} required disabled={busy} placeholder={'08012345678\n08023456789\n…'} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-mono text-sm font-normal" /><span className="mt-1 block text-xs font-normal text-[var(--muted)]">{recipients.length} recipients · minimum 10, maximum 100.</span></label>
          <div className="rounded-xl bg-[var(--page)] p-4 text-sm"><div className="flex justify-between gap-3"><span className="text-[var(--muted)]">Estimated total</span><strong>{money(perItemMinor * recipients.length)}</strong></div><p className="mt-2 text-xs leading-5 text-[var(--muted)]">Preview only. The server recalculates the charge for every item before accepting the batch.</p></div>
          <label className="block text-sm font-semibold">Transaction PIN<input type="password" inputMode="numeric" autoComplete="off" value={pin} onChange={(event) => setPin(event.target.value.replace(/\D/g, '').slice(0, 4))} maxLength={4} minLength={4} required disabled={busy} placeholder="4 digits" className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal" /></label>
          <button type="submit" disabled={busy || !product || recipients.length < 10 || recipients.length > 100 || !/^\d{4}$/.test(pin)} className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white disabled:opacity-50">{busy && <LoaderCircle size={18} className="animate-spin" />}{busy ? 'Submitting securely…' : 'Submit bulk batch'}</button>
          <p className="text-center text-xs leading-5 text-[var(--muted)]">Each item has its own idempotency key and order. Unknown provider outcomes are held for reconciliation, not blindly retried.</p>
        </form>
      )}
    </section>
  );
}
