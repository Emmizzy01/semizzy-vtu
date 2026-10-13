import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { AlertCircle, CheckCircle2, Clock3, LoaderCircle, RefreshCw, Smartphone, ShieldCheck } from 'lucide-react';

type Product = {
  code: string;
  service_type: 'airtime' | 'data';
  network: string;
  name: string;
  price_minor: number;
  face_value_minor: number | null;
  currency: string;
};

type Order = {
  id: string;
  service_type: string;
  network: string;
  product_code: string;
  recipient: string;
  amount_minor: number;
  currency: string;
  status: string;
  provider_reference: string | null;
  failure_code: string | null;
  created_at: string | null;
  completed_at: string | null;
};

function newRequestKey() {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }
  return `vtu-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function naira(amountMinor: number) {
  return new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 2,
  }).format(amountMinor / 100);
}

async function readMessage(response: Response) {
  const body = await response.json().catch(() => ({}));
  return typeof body.message === 'string' ? body.message : 'Something went wrong. Please try again.';
}

export default function VtuPurchase() {
  const [serviceType, setServiceType] = useState<'airtime' | 'data'>('airtime');
  const [products, setProducts] = useState<Product[]>([]);
  const [productCode, setProductCode] = useState('');
  const [recipient, setRecipient] = useState('');
  const [requestKey, setRequestKey] = useState(newRequestKey);
  const [order, setOrder] = useState<Order | null>(null);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [submissionUncertain, setSubmissionUncertain] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoadingProducts(true);
    setError('');
    setProductCode('');

    fetch(`/wallet/vtu/products?service_type=${encodeURIComponent(serviceType)}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(async (response) => {
        if (!response.ok) throw new Error(await readMessage(response));
        return response.json();
      })
      .then((body) => {
        if (cancelled) return;
        const list = Array.isArray(body.data) ? body.data as Product[] : [];
        setProducts(list);
        setProductCode(list[0]?.code ?? '');
      })
      .catch((cause: unknown) => {
        if (!cancelled) setError(cause instanceof Error ? cause.message : 'Products could not be loaded.');
      })
      .finally(() => {
        if (!cancelled) setLoadingProducts(false);
      });

    return () => { cancelled = true; };
  }, [serviceType]);

  const networks = useMemo(
    () => Array.from(new Set(products.map((product) => product.network))),
    [products],
  );
  const selectedProduct = products.find((product) => product.code === productCode);

  async function submitPurchase(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || !selectedProduct || !recipient.trim()) return;

    setSubmitting(true);
    setError('');

    let receivedResponse = false;
    try {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
      const response = await fetch('/wallet/vtu/orders', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({
          product_code: selectedProduct.code,
          recipient,
          idempotency_key: requestKey,
        }),
      });

      receivedResponse = true;
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(typeof body.message === 'string' ? body.message : 'Purchase could not be submitted.');
      }
      setSubmissionUncertain(false);
      setOrder(body.data as Order);
    } catch (cause: unknown) {
      if (!receivedResponse) setSubmissionUncertain(true);
      setError(cause instanceof Error ? cause.message : 'The result is unknown. Keep this form unchanged and retry safely.');
    } finally {
      setSubmitting(false);
    }
  }

  async function refreshOrder() {
    if (!order) return;
    setRefreshing(true);
    setError('');
    try {
      const response = await fetch(`/wallet/vtu/orders/${encodeURIComponent(order.id)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error(await readMessage(response));
      const body = await response.json();
      setOrder(body.data as Order);
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : 'Order status could not be refreshed.');
    } finally {
      setRefreshing(false);
    }
  }

  const terminal = order && ['successful', 'failed', 'cancelled'].includes(order.status);

  return (
    <>
      <Head title="Buy airtime and data" />
      <main className="min-h-screen bg-[var(--page)] px-4 py-8 text-[var(--ink)] sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl">
          <header className="mb-8 flex items-center justify-between gap-4">
            <a href="/" className="flex items-center gap-3" aria-label="SEMIZZY home">
              <span className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span>
                <span className="block font-display text-lg font-extrabold">SEMIZZY</span>
                <span className="block text-xs text-[var(--muted)]">Airtime & data</span>
              </span>
            </a>
            <span className="inline-flex items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs font-semibold text-[var(--muted)]">
              <ShieldCheck size={15} /> Secure checkout
            </span>
          </header>

          <section className="rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 shadow-sm sm:p-8">
            <div className="mb-7 flex items-start gap-4">
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-[var(--brand)]/10 text-[var(--brand)]">
                <Smartphone size={24} />
              </span>
              <div>
                <h1 className="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">Buy airtime or data</h1>
                <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Choose a listed product and confirm the recipient number before submitting.</p>
              </div>
            </div>

            {error && (
              <div role="alert" className="mb-5 flex items-start gap-3 rounded-2xl border border-rose-500/30 bg-rose-500/5 p-4 text-sm">
                <AlertCircle size={18} className="mt-0.5 shrink-0 text-rose-500" />
                <p>{error}</p>
              </div>
            )}

            {order ? (
              <div className="space-y-5">
                <div className="rounded-2xl border border-[var(--line)] p-5">
                  <div className="flex items-center gap-3">
                    {order.status === 'successful'
                      ? <CheckCircle2 className="text-emerald-500" size={24} />
                      : order.status === 'failed'
                        ? <AlertCircle className="text-rose-500" size={24} />
                        : <Clock3 className="text-amber-500" size={24} />}
                    <div>
                      <h2 className="font-bold capitalize">{order.status.replaceAll('_', ' ')}</h2>
                      <p className="mt-1 text-sm text-[var(--muted)]">{order.network} · {order.recipient}</p>
                    </div>
                  </div>
                  <div className="mt-5 flex items-center justify-between border-t border-[var(--line)] pt-4">
                    <span className="text-sm text-[var(--muted)]">Charged amount</span>
                    <strong>{naira(order.amount_minor)}</strong>
                  </div>
                  <p className="mt-3 break-all text-xs text-[var(--muted)]">Order reference: {order.id}</p>
                  {order.status === 'awaiting_reconciliation' && (
                    <p className="mt-3 text-sm leading-6 text-amber-600">The provider has not confirmed the result. Your order is being held for reconciliation. Do not submit a new purchase for this request.</p>
                  )}
                  {order.status === 'processing' && (
                    <p className="mt-3 text-sm leading-6 text-[var(--muted)]">The provider is still processing this order. Refresh the status instead of submitting another purchase.</p>
                  )}
                  {order.status === 'failed' && (
                    <p className="mt-3 text-sm leading-6 text-[var(--muted)]">The provider reported a definitive failure. Semizzy has recorded the order for a ledger-based refund.</p>
                  )}
                </div>
                {!terminal && (
                  <button type="button" onClick={refreshOrder} disabled={refreshing} className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-[var(--line)] px-4 py-3 font-semibold transition hover:bg-[var(--page)] disabled:opacity-60">
                    {refreshing ? <LoaderCircle className="animate-spin" size={18} /> : <RefreshCw size={18} />}
                    Refresh order status
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => {
                    setOrder(null);
                    setError('');
                    setRequestKey(newRequestKey());
                    setSubmissionUncertain(false);
                  }}
                  className="w-full rounded-xl bg-[var(--brand)] px-4 py-3 font-bold text-white transition hover:opacity-90"
                >
                  Start another purchase
                </button>
              </div>
            ) : (
              <form onSubmit={submitPurchase} className="space-y-6">
                <fieldset>
                  <legend className="mb-3 text-sm font-bold">What do you want to buy?</legend>
                  <div className="grid grid-cols-2 gap-3">
                    {(['airtime', 'data'] as const).map((type) => (
                      <button
                        key={type}
                        type="button"
                        onClick={() => {
                          if (serviceType !== type) {
                            setServiceType(type);
                            setRequestKey(newRequestKey());
                          }
                        }}
                        aria-pressed={serviceType === type}
                        className={`rounded-xl border px-4 py-3 text-sm font-bold capitalize transition ${serviceType === type ? 'border-[var(--brand)] bg-[var(--brand)]/10 text-[var(--brand)]' : 'border-[var(--line)] hover:bg-[var(--page)]'}`}
                      >
                        {type}
                      </button>
                    ))}
                  </div>
                </fieldset>

                <div className="grid gap-5 sm:grid-cols-2">
                  <label className="block text-sm font-semibold">
                    Network
                    <select
                      value={selectedProduct?.network ?? ''}
                      onChange={(event) => {
                        const firstForNetwork = products.find((product) => product.network === event.target.value);
                        setProductCode(firstForNetwork?.code ?? '');
                        setRequestKey(newRequestKey());
                      }}
                      disabled={loadingProducts || networks.length === 0 || submissionUncertain}
                      className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal outline-none focus:border-[var(--brand)]"
                    >
                      {networks.map((network) => <option key={network} value={network}>{network}</option>)}
                    </select>
                  </label>

                  <label className="block text-sm font-semibold">
                    Product
                    <select
                      value={productCode}
                      onChange={(event) => {
                        setProductCode(event.target.value);
                        setRequestKey(newRequestKey());
                      }}
                      disabled={loadingProducts || products.length === 0 || submissionUncertain}
                      className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal outline-none focus:border-[var(--brand)]"
                    >
                      {products.filter((product) => !selectedProduct || product.network === selectedProduct.network).map((product) => (
                        <option key={product.code} value={product.code}>{product.name} · {naira(product.price_minor)}</option>
                      ))}
                    </select>
                  </label>
                </div>

                <label className="block text-sm font-semibold">
                  Recipient phone number
                  <input
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    value={recipient}
                    onChange={(event) => {
                      setRecipient(event.target.value);
                      setRequestKey(newRequestKey());
                    }}
                    placeholder="e.g. 08012345678"
                    maxLength={32}
                    required
                    disabled={submissionUncertain}
                    className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]"
                  />
                </label>

                {loadingProducts ? (
                  <p className="flex items-center gap-2 text-sm text-[var(--muted)]"><LoaderCircle className="animate-spin" size={16} /> Loading products…</p>
                ) : products.length === 0 ? (
                  <p className="rounded-xl border border-dashed border-[var(--line)] p-4 text-sm leading-6 text-[var(--muted)]">
                    No {serviceType} products are configured yet. Purchases stay disabled until the approved provider catalogue and prices are configured.
                  </p>
                ) : selectedProduct ? (
                  <div className="rounded-2xl bg-[var(--page)] p-4">
                    <div className="flex items-center justify-between gap-4 text-sm">
                      <span className="text-[var(--muted)]">Total charge</span>
                      <strong className="text-lg">{naira(selectedProduct.price_minor)}</strong>
                    </div>
                    <p className="mt-2 text-xs leading-5 text-[var(--muted)]">Check the phone number carefully. Once provider dispatch begins, cancellation may not be possible.</p>
                  </div>
                ) : null}

                <button
                  type="submit"
                  disabled={submitting || loadingProducts || !selectedProduct || !recipient.trim()}
                  className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {submitting && <LoaderCircle className="animate-spin" size={18} />}
                  {submitting ? 'Submitting securely…' : submissionUncertain ? 'Retry same request safely' : selectedProduct ? `Buy for ${naira(selectedProduct.price_minor)}` : 'Choose a product'}
                </button>
                <p className="text-center text-xs leading-5 text-[var(--muted)]">Duplicate requests use the same idempotency key. If the result is uncertain, Semizzy will reconcile the existing order rather than submit another one.</p>
              </form>
            )}
          </section>
        </div>
      </main>
    </>
  );
}
