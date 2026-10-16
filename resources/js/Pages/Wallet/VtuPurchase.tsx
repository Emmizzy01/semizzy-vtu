import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { AlertCircle, CheckCircle2, Clock3, LoaderCircle, RefreshCw, Smartphone, ShieldCheck } from 'lucide-react';

type ServiceType = 'airtime' | 'data' | 'electricity-bill' | 'tv-subscription';

type Product = {
  code: string;
  service_type: ServiceType;
  network: string;
  name: string;
  price_minor: number;
  face_value_minor: number | null;
  currency: string;
  metadata?: Record<string, unknown>;
};

type PendingPurchase = {
  serviceType: ServiceType;
  productCode: string;
  recipient: string;
  requestKey: string;
  uncertain: boolean;
};

const PENDING_PURCHASE_KEY = 'semizzy-vtu-pending-purchase';

function readPendingPurchase(): PendingPurchase | null {
  try {
    const raw = window.sessionStorage.getItem(PENDING_PURCHASE_KEY);
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<PendingPurchase>;
    if ((!['airtime', 'data', 'electricity-bill', 'tv-subscription'].includes(value.serviceType ?? ''))
      || typeof value.productCode !== 'string'
      || typeof value.recipient !== 'string'
      || typeof value.requestKey !== 'string'
      || typeof value.uncertain !== 'boolean') return null;
    return value as PendingPurchase;
  } catch {
    return null;
  }
}

function savePendingPurchase(value: PendingPurchase) {
  try { window.sessionStorage.setItem(PENDING_PURCHASE_KEY, JSON.stringify(value)); } catch { /* optional browser storage */ }
}

function clearPendingPurchase() {
  try { window.sessionStorage.removeItem(PENDING_PURCHASE_KEY); } catch { /* optional browser storage */ }
}

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

function productLabel(product: Product) {
  const details: string[] = [];
  const validity = product.metadata?.validity;
  const dataType = product.metadata?.data_type;
  if (typeof validity === 'string' && validity.trim() && !product.name.toLowerCase().includes(validity.toLowerCase())) {
    details.push(validity.trim());
  }
  if (typeof dataType === 'string' && dataType.trim() && !product.name.toLowerCase().includes(dataType.toLowerCase())) {
    details.push(dataType.trim());
  }
  return `${product.name}${details.length ? ` (${details.join(' · ')})` : ''} · ${naira(product.price_minor)}`;
}

async function readMessage(response: Response) {
  const body = await response.json().catch(() => ({}));
  return typeof body.message === 'string' ? body.message : 'Something went wrong. Please try again.';
}

export default function VtuPurchase() {
  const [restoredPending] = useState(readPendingPurchase);
  const [serviceType, setServiceType] = useState<ServiceType>(restoredPending?.serviceType ?? 'airtime');
  const [products, setProducts] = useState<Product[]>([]);
  const [productCode, setProductCode] = useState(restoredPending?.productCode ?? '');
  const [recipient, setRecipient] = useState(restoredPending?.recipient ?? '');
  const [transactionPin, setTransactionPin] = useState('');
  const [meterType, setMeterType] = useState<'prepaid' | 'postpaid'>('prepaid');
  const [contactPhone, setContactPhone] = useState('');
  const [electricityAmount, setElectricityAmount] = useState('');
  const [verification, setVerification] = useState<{ status: string; customer_name: string | null; customer_address: string | null } | null>(null);
  const [verifying, setVerifying] = useState(false);
  const [requestKey, setRequestKey] = useState(restoredPending?.requestKey ?? newRequestKey);
  const [order, setOrder] = useState<Order | null>(null);
  const [loadingProducts, setLoadingProducts] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [submissionUncertain, setSubmissionUncertain] = useState(restoredPending?.uncertain ?? false);

  useEffect(() => {
    let cancelled = false;
    setLoadingProducts(true);
    setError('');
    setProductCode('');
    setVerification(null);

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
        setProductCode((current) => current && list.some((product) => product.code === current) ? current : (list[0]?.code ?? ''));
      })
      .catch((cause: unknown) => {
        if (!cancelled) setError(cause instanceof Error ? cause.message : 'Products could not be loaded.');
      })
      .finally(() => {
        if (!cancelled) setLoadingProducts(false);
      });

    return () => { cancelled = true; };
  }, [serviceType]);

  useEffect(() => {
    if (!restoredPending?.uncertain) return;
    let cancelled = false;
    fetch(`/wallet/vtu/orders/recover?idempotency_key=${encodeURIComponent(restoredPending.requestKey)}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    }).then(async (response) => {
      if (!response.ok) return null;
      const body = await response.json().catch(() => ({}));
      return body.data as Order | null;
    }).then((existingOrder) => {
      if (cancelled || !existingOrder) return;
      setOrder(existingOrder);
      setSubmissionUncertain(false);
      clearPendingPurchase();
    }).catch(() => {
      // Preserve the uncertain request. The customer can check again manually.
    });
    return () => { cancelled = true; };
  }, [restoredPending]);

  const networks = useMemo(
    () => Array.from(new Set(products.map((product) => product.network))),
    [products],
  );
  const selectedProduct = products.find((product) => product.code === productCode);
  const isElectricity = serviceType === 'electricity-bill';
  const isTv = serviceType === 'tv-subscription';
  const needsVerification = isElectricity || isTv;
  const displayedAmountMinor = isElectricity ? Math.round(Number(electricityAmount || 0) * 100) : (selectedProduct?.price_minor ?? 0);
  const electricityMinimum = isElectricity && typeof selectedProduct?.metadata?.provider_minimum_amount === 'number'
    ? selectedProduct.metadata.provider_minimum_amount
    : 1;
  const electricityMaximum = isElectricity && typeof selectedProduct?.metadata?.provider_maximum_amount === 'number'
    ? selectedProduct.metadata.provider_maximum_amount
    : 1000000;

  async function submitPurchase(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || !selectedProduct || !recipient.trim() || (needsVerification && verification?.status !== 'verified')) return;

    setSubmitting(true);
    setError('');

    let receivedResponse = false;
    savePendingPurchase({ serviceType, productCode: selectedProduct.code, recipient, requestKey, uncertain: false });
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
          transaction_pin: transactionPin,
          ...(needsVerification ? { contact_phone: contactPhone, ...(isElectricity ? { meter_type: meterType, amount: electricityAmount } : {}) } : {}),
        }),
      });

      receivedResponse = true;
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        if (response.status >= 500) {
          setSubmissionUncertain(true);
          savePendingPurchase({ serviceType, productCode: selectedProduct.code, recipient, requestKey, uncertain: true });
        } else {
          clearPendingPurchase();
          setSubmissionUncertain(false);
        }
        throw new Error(typeof body.message === 'string' ? body.message : 'Purchase could not be submitted.');
      }
      clearPendingPurchase();
      setSubmissionUncertain(false);
      setOrder(body.data as Order);
    } catch (cause: unknown) {
      if (!receivedResponse) {
        setSubmissionUncertain(true);
        savePendingPurchase({ serviceType, productCode: selectedProduct.code, recipient, requestKey, uncertain: true });
      }
      setError(cause instanceof Error ? cause.message : 'The result is unknown. Keep this form unchanged and retry safely.');
    } finally {
      setSubmitting(false);
      setTransactionPin('');
    }
  }

  async function recoverOrder() {
    if (!restoredPending && !submissionUncertain) return;
    const key = restoredPending?.requestKey ?? requestKey;
    setRefreshing(true);
    setError('');
    try {
      const response = await fetch(`/wallet/vtu/orders/recover?idempotency_key=${encodeURIComponent(key)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      const body = await response.json().catch(() => ({}));
      if (response.status === 404) {
        throw new Error(typeof body.message === 'string' ? body.message : 'No saved order is visible yet. Check again before starting another purchase.');
      }
      if (!response.ok || !body.data) {
        throw new Error(typeof body.message === 'string' ? body.message : 'The existing order could not be found. Keep this request unchanged and check again.');
      }
      setOrder(body.data as Order);
      setSubmissionUncertain(false);
      clearPendingPurchase();
    } catch (cause: unknown) {
      setError(cause instanceof Error ? cause.message : 'Could not check the existing order. Do not submit another purchase yet.');
    } finally {
      setRefreshing(false);
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
      <Head title="Buy services" />
      <main className="min-h-screen bg-[var(--page)] px-4 py-8 text-[var(--ink)] sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl">
          <header className="mb-8 flex items-center justify-between gap-4">
            <a href="/" className="flex items-center gap-3" aria-label="SEMIZZY home">
              <span className="grid h-11 w-11 place-items-center rounded-2xl bg-[var(--brand)] font-display text-lg font-extrabold text-white">S</span>
              <span>
                <span className="block font-display text-lg font-extrabold">SEMIZZY</span>
                <span className="block text-xs text-[var(--muted)]">Digital services</span>
              </span>
            </a>
            <div className="flex shrink-0 items-center gap-2">
              <a href="/dashboard" className="inline-flex min-h-10 items-center rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs font-bold text-[var(--ink)] hover:border-[var(--brand)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2">Dashboard</a>
              <span className="hidden items-center gap-2 rounded-full border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs font-semibold text-[var(--muted)] sm:inline-flex">
                <ShieldCheck size={15} aria-hidden="true" /> Secure checkout
              </span>
            </div>
          </header>

          <section className="rounded-3xl border border-[var(--line)] bg-[var(--surface)] p-5 shadow-sm sm:p-8">
            <div className="mb-7 flex items-start gap-4">
              <span className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-[var(--brand)]/10 text-[var(--brand)]">
                <Smartphone size={24} />
              </span>
              <div>
                <h1 className="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">Buy digital services</h1>
                <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Choose a service and verify the meter or smartcard before any bill payment.</p>
              </div>
            </div>

            {error && (
              <div role="alert" className="mb-5 flex items-start gap-3 rounded-2xl border border-rose-500/30 bg-rose-500/5 p-4 text-sm">
                <AlertCircle size={18} className="mt-0.5 shrink-0 text-rose-500" />
                <div><p>{error}</p><a className="mt-2 inline-block font-semibold text-[var(--brand)] underline" href="/security/transaction-pin">Set up or manage transaction PIN</a></div>
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
                    <p className="mt-3 text-sm leading-6 text-[var(--muted)]">The provider reported a definitive failure. The wallet refund has been recorded through Semizzy’s ledger.</p>
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
                    clearPendingPurchase();
                    setSubmissionUncertain(false);
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
                    {(['airtime', 'data', 'electricity-bill', 'tv-subscription'] as const).map((type) => (
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
                        disabled={submissionUncertain}
                        className={`rounded-xl border px-4 py-3 text-sm font-bold transition ${serviceType === type ? 'border-[var(--brand)] bg-[var(--brand)]/10 text-[var(--brand)]' : 'border-[var(--line)] hover:bg-[var(--page)]'}`}
                      >
                        {{ airtime: 'Airtime', data: 'Mobile data', 'electricity-bill': 'Electricity', 'tv-subscription': 'TV subscription' }[type]}
                      </button>
                    ))}
                  </div>
                </fieldset>

                <div className="grid gap-5 sm:grid-cols-2">
                  <label className="block text-sm font-semibold">
                    Provider
                    <select
                      value={selectedProduct?.network ?? ''}
                      onChange={(event) => {
                        const firstForNetwork = products.find((product) => product.network === event.target.value);
                        setProductCode(firstForNetwork?.code ?? '');
                        setVerification(null);
                        setRequestKey(newRequestKey());
                      }}
                      disabled={loadingProducts || networks.length === 0 || submissionUncertain}
                      className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
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
                        setVerification(null);
                        setRequestKey(newRequestKey());
                      }}
                      disabled={loadingProducts || products.length === 0 || submissionUncertain}
                      className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal outline-none focus:border-[var(--brand)]"
                    >
                      {products.filter((product) => !selectedProduct || product.network === selectedProduct.network).map((product) => (
                        <option key={product.code} value={product.code}>{productLabel(product)}</option>
                      ))}
                    </select>
                  </label>
                </div>

                <label className="block text-sm font-semibold">
                  {isElectricity ? 'Meter number' : isTv ? 'TV smartcard / decoder number' : 'Recipient phone number'}
                  <input
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    value={recipient}
                    onChange={(event) => {
                      setRecipient(event.target.value);
                      setVerification(null);
                      setRequestKey(newRequestKey());
                    }}
                    placeholder={isElectricity ? "Enter your electricity meter number" : isTv ? "Enter your TV smartcard number" : "e.g. 08012345678"}
                    maxLength={40}
                    required
                    disabled={submissionUncertain}
                    className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
                  />
                </label>

                {needsVerification && (
                  <div className="space-y-4 rounded-2xl border border-[var(--line)] p-4">
                    {isElectricity && (
                      <>
                        <label className="block text-sm font-semibold">Meter type
                          <select value={meterType} onChange={(event) => { setMeterType(event.target.value as 'prepaid' | 'postpaid'); setVerification(null); setRequestKey(newRequestKey()); }} disabled={submissionUncertain} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal">
                            <option value="prepaid">Prepaid</option><option value="postpaid">Postpaid</option>
                          </select>
                        </label>
                        <label className="block text-sm font-semibold">Amount (₦)
                          <input type="number" inputMode="decimal" min={electricityMinimum} max={electricityMaximum} step="1" value={electricityAmount} onChange={(event) => { setElectricityAmount(event.target.value); setRequestKey(newRequestKey()); }} required disabled={submissionUncertain} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal" placeholder="Enter purchase amount" />
                        </label>
                      </>
                    )}
                    <label className="block text-sm font-semibold">Contact phone number
                      <input type="tel" inputMode="tel" value={contactPhone} onChange={(event) => { setContactPhone(event.target.value); setVerification(null); }} required disabled={submissionUncertain} maxLength={20} placeholder="08012345678" className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal" />
                    </label>
                    <button type="button" disabled={submissionUncertain || verifying || !selectedProduct || !recipient.trim() || !contactPhone.trim() || (isElectricity && !electricityAmount)} onClick={async () => {
                      setVerifying(true); setError(''); setVerification(null);
                      try {
                        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
                        const response = await fetch('/wallet/vtu/verify-biller', {
                          method: 'POST', credentials: 'same-origin',
                          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                          body: JSON.stringify({ product_code: selectedProduct?.code, biller_code: recipient, ...(isElectricity ? { meter_type: meterType } : {}) }),
                        });
                        const body = await response.json().catch(() => ({}));
                        if (!response.ok && response.status !== 503) throw new Error(typeof body.message === 'string' ? body.message : 'Account verification failed.');
                        setVerification(body.data ?? { status: 'unknown', customer_name: null, customer_address: null });
                        if (body.data?.status !== 'verified') setError(typeof body.message === 'string' ? body.message : 'Account verification is inconclusive. Do not purchase yet.');
                      } catch (cause: unknown) {
                        setError(cause instanceof Error ? cause.message : 'Account verification failed.');
                      } finally { setVerifying(false); }
                    }} className="w-full rounded-xl border border-[var(--brand)] px-4 py-3 font-bold text-[var(--brand)] disabled:opacity-50">
                      {verifying ? 'Verifying account…' : verification?.status === 'verified' ? 'Verify again' : 'Verify account details'}
                    </button>
                    {verification?.status === 'verified' && (
                      <div role="status" className="rounded-xl bg-emerald-500/10 p-3 text-sm">
                        <p className="font-semibold text-emerald-700">Account verified</p>
                        {verification.customer_name && <p className="mt-1">Customer: {verification.customer_name}</p>}
                        {verification.customer_address && <p className="mt-1">{verification.customer_address}</p>}
                        <p className="mt-1 text-xs">Semizzy will verify again before debiting your wallet.</p>
                      </div>
                    )}
                  </div>
                )}

                <label className="block text-sm font-semibold">
                  Transaction PIN
                  <input type="password" inputMode="numeric" autoComplete="off" value={transactionPin} onChange={(event) => setTransactionPin(event.target.value.replace(/\D/g, '').slice(0, 6))} disabled={submissionUncertain} placeholder="4 digits (or 6 for an older PIN)" maxLength={6} minLength={4} required className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />
                  <span className="mt-1 block text-xs font-normal text-[var(--muted)]">Separate from your login password. PIN is not saved in the pending-order browser record.</span>
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
                      <strong className="text-lg">{naira(displayedAmountMinor)}</strong>
                    </div>
                    {serviceType === 'data' && (
                      <div className="mt-3 flex flex-wrap gap-2">
                        {typeof selectedProduct.metadata?.validity === 'string' && selectedProduct.metadata.validity && <span className="rounded-full border border-[var(--line)] px-3 py-1 text-xs">Validity: {selectedProduct.metadata.validity}</span>}
                        {typeof selectedProduct.metadata?.data_type === 'string' && selectedProduct.metadata.data_type && <span className="rounded-full border border-[var(--line)] px-3 py-1 text-xs">Plan type: {selectedProduct.metadata.data_type}</span>}
                      </div>
                    )}
                    <p className="mt-2 text-xs leading-5 text-[var(--muted)]">Check the account number and customer details carefully. Once provider dispatch begins, cancellation may not be possible.</p>
                  </div>
                ) : null}

                {submissionUncertain && (
                  <div className="space-y-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4">
                    <p className="text-sm leading-6 text-amber-700">The previous request may already have reached the provider. Semizzy is checking for its existing order. Do not submit a second purchase while the result is uncertain.</p>
                    <button type="button" onClick={recoverOrder} disabled={refreshing} className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-amber-600 px-4 py-3 font-bold text-amber-700 disabled:opacity-60">
                      {refreshing ? <LoaderCircle className="animate-spin" size={18} /> : <RefreshCw size={18} />}
                      Check existing order status
                    </button>
                  </div>
                )}
                <button
                  type="submit"
                  disabled={submissionUncertain || submitting || loadingProducts || !selectedProduct || !recipient.trim() || (needsVerification && verification?.status !== 'verified') || (isElectricity && (displayedAmountMinor < electricityMinimum * 100 || displayedAmountMinor > electricityMaximum * 100))}
                  className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {submitting && <LoaderCircle className="animate-spin" size={18} />}
                  {submitting ? 'Submitting securely…' : submissionUncertain ? 'Check existing order status above' : selectedProduct ? `Continue for ${naira(displayedAmountMinor)}` : 'Choose a product'}
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
