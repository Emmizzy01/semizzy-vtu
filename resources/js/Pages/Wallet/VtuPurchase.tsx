import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { AlertCircle, CheckCircle2, Clock3, LoaderCircle, RefreshCw, Smartphone, ShieldCheck } from 'lucide-react';

type ServiceType = 'airtime' | 'data' | 'electricity-bill' | 'tv-subscription';

type SavedBeneficiary = { id: number; service_type: string; label: string; recipient: string; network: string | null; product_code: string | null; service_details: Record<string, unknown> | null };

type Product = {
  code: string;
  service_type: ServiceType;
  network: string;
  name: string;
  price_minor: number;
  face_value_minor: number | null;
  currency: string;
  is_active: boolean;
  metadata?: Record<string, unknown>;
};

type DetectedNetwork = { label: string; prefixes: string[] };

/**
 * Prefix-based network hint only. Nigerian mobile-number portability means
 * this must never be represented as authoritative current-carrier verification.
 */
const NIGERIAN_NETWORK_PREFIXES: DetectedNetwork[] = [
  { label: 'MTN', prefixes: ['0703', '0706', '0803', '0806', '0810', '0813', '0814', '0816', '0903', '0906', '0913', '0916'] },
  { label: 'Airtel', prefixes: ['0701', '0708', '0802', '0808', '0812', '0901', '0902', '0907', '0912'] },
  { label: 'Glo', prefixes: ['0705', '0805', '0807', '0811', '0815', '0905', '0915'] },
  { label: '9mobile', prefixes: ['0809', '0817', '0818', '0908', '0909'] },
];

function suggestNigerianNetwork(input: string): string | null {
  let digits = input.replace(/\D/g, '');
  if (digits.startsWith('234')) digits = `0${digits.slice(3)}`;
  if (digits.length < 4 || !digits.startsWith('0')) return null;
  return NIGERIAN_NETWORK_PREFIXES.find((network) => network.prefixes.includes(digits.slice(0, 4)))?.label ?? null;
}

function networkMatchesLabel(network: string, label: string): boolean {
  const normalized = network.toLowerCase().replace(/[^a-z0-9]/g, '');
  const expected = label.toLowerCase().replace(/[^a-z0-9]/g, '');
  if (expected === '9mobile') return normalized.includes('9mobile') || normalized.includes('etisalat');
  return normalized.includes(expected);
}

type PendingPurchase = {
  serviceType: ServiceType;
  productCode: string;
  recipient: string;
  requestKey: string;
  uncertain: boolean;
  storefrontSlug?: string;
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
      || typeof value.uncertain !== 'boolean'
      || (value.storefrontSlug !== undefined && typeof value.storefrontSlug !== 'string')) return null;
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

function productLabel(product: Product, optionLabel = '') {
  // Airtime is amount-based; duplicate provider routes remain separate neutral options.
  if (product.service_type === 'airtime') return `${product.name} · Enter amount${optionLabel ? ` · ${optionLabel}` : ''}`;
  const details: string[] = [];
  const validity = product.metadata?.validity;
  const dataType = product.metadata?.data_type;
  if (typeof validity === 'string' && validity.trim() && !product.name.toLowerCase().includes(validity.toLowerCase())) {
    details.push(validity.trim());
  }
  if (typeof dataType === 'string' && dataType.trim() && !product.name.toLowerCase().includes(dataType.toLowerCase())) {
    details.push(dataType.trim());
  }
  return `${product.name}${details.length ? ` (${details.join(' · ')})` : ''} · ${naira(product.price_minor)}${optionLabel ? ` · ${optionLabel}` : ''}`;
}

function sameCustomerPlan(a: Product, b: Product) {
  return a.service_type === b.service_type
    && a.network === b.network
    && a.name.trim().toLowerCase() === b.name.trim().toLowerCase()
    && a.price_minor === b.price_minor
    && String(a.metadata?.validity ?? '') === String(b.metadata?.validity ?? '')
    && String(a.metadata?.data_type ?? '') === String(b.metadata?.data_type ?? '');
}

async function readMessage(response: Response) {
  const body = await response.json().catch(() => ({}));
  return typeof body.message === 'string' ? body.message : 'Something went wrong. Please try again.';
}

export default function VtuPurchase({ serviceType }: { serviceType: ServiceType }) {
  const [restoredPending] = useState(readPendingPurchase);
  const [products, setProducts] = useState<Product[]>([]);
  const [productCode, setProductCode] = useState(restoredPending?.productCode ?? '');
  const [recipient, setRecipient] = useState(restoredPending?.recipient ?? '');
  const [beneficiaries, setBeneficiaries] = useState<SavedBeneficiary[]>([]);
  const [savingBeneficiary, setSavingBeneficiary] = useState(false);
  const [scheduleMode, setScheduleMode] = useState(false);
  const [scheduleFrequency, setScheduleFrequency] = useState<'daily' | 'weekly' | 'monthly'>('monthly');
  const [scheduleNextRun, setScheduleNextRun] = useState(() => { const d = new Date(Date.now() + 5 * 60_000); d.setSeconds(0, 0); return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16); });
  const [scheduleMessage, setScheduleMessage] = useState('');
  const [transactionPin, setTransactionPin] = useState('');
  const [meterType, setMeterType] = useState<'prepaid' | 'postpaid'>('prepaid');
  const [contactPhone, setContactPhone] = useState('');
  const [purchaseAmount, setPurchaseAmount] = useState('');
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
    fetch(`/wallet/service-beneficiaries?service_type=${encodeURIComponent(serviceType)}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    }).then(async (response) => {
      if (!response.ok) throw new Error('Saved beneficiaries could not be loaded.');
      return response.json();
    }).then((body) => {
      if (!cancelled) setBeneficiaries(Array.isArray(body.data) ? body.data as SavedBeneficiary[] : []);
    }).catch(() => {
      if (!cancelled) setBeneficiaries([]);
    });
    return () => { cancelled = true; };
  }, [serviceType]);

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
        const rawList = Array.isArray(body.data) ? body.data as Product[] : [];
        const list = rawList;
        setProducts(list);
        const query = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
        const requestedCode = query?.get('product_code') ?? undefined;
        const requestedNetwork = query?.get('network')?.trim().toLowerCase() ?? undefined;
        const requestedProduct = requestedCode ? list.find((product) => product.code === requestedCode && product.is_active) : undefined;
        const preferredProduct = requestedNetwork
          ? list.find((product) => product.network.trim().toLowerCase() === requestedNetwork && product.is_active)
          : undefined;
        setProductCode((current) => current && list.some((product) => product.code === current && product.is_active)
          ? current
          : (requestedProduct?.code ?? preferredProduct?.code ?? list.find((product) => product.is_active)?.code ?? ''));
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
  const detectedNetwork = (serviceType === 'airtime' || serviceType === 'data')
    ? suggestNigerianNetwork(recipient)
    : null;
  const suggestedNetworkProduct = detectedNetwork
    ? products.find((product) => product.is_active && networkMatchesLabel(product.network, detectedNetwork))
    : undefined;
  const isElectricity = serviceType === 'electricity-bill';
  const isTv = serviceType === 'tv-subscription';
  const needsVerification = isElectricity || isTv;
  const isAirtime = serviceType === 'airtime';
  const displayedAmountMinor = isElectricity || isAirtime ? Math.round(Number(purchaseAmount || 0) * 100) : (selectedProduct?.price_minor ?? 0);
  const electricityMinimum = isElectricity && typeof selectedProduct?.metadata?.provider_minimum_amount === 'number'
    ? selectedProduct.metadata.provider_minimum_amount
    : 1;
  const electricityMaximum = isElectricity && typeof selectedProduct?.metadata?.provider_maximum_amount === 'number'
    ? selectedProduct.metadata.provider_maximum_amount
    : 1000000;

  async function submitPurchase(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting || !selectedProduct || !selectedProduct.is_active || !recipient.trim() || (needsVerification && verification?.status !== 'verified')) return;

    setSubmitting(true);
    setError('');
    setScheduleMessage('');

    if (scheduleMode) {
      try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const response = await fetch('/wallet/scheduled-purchases', {
          method: 'POST', credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({
            service_type: serviceType, product_code: selectedProduct.code, recipient,
            ...(isAirtime || isElectricity ? { amount: purchaseAmount } : {}),
            frequency: scheduleFrequency, next_run_at: new Date(scheduleNextRun).toISOString(), transaction_pin: transactionPin,
            ...(needsVerification ? { contact_phone: contactPhone, ...(isElectricity ? { meter_type: meterType } : {}), ...(isTv ? { subscription_type: 'change' } : {}) } : {}),
          }),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(typeof body.message === 'string' ? body.message : 'Schedule could not be saved.');
        setScheduleMessage('Recurring purchase schedule saved. Semizzy will re-check product availability, account verification where required, wallet funds and provider status at run time.');
        setTransactionPin('');
        setScheduleMode(false);
      } catch (cause: unknown) {
        setError(cause instanceof Error ? cause.message : 'Schedule could not be saved.');
      } finally { setSubmitting(false); }
      return;
    }

    let receivedResponse = false;
    const storefrontSlug = new URLSearchParams(window.location.search).get('storefront')?.trim() || undefined;
    savePendingPurchase({ serviceType, productCode: selectedProduct.code, recipient, requestKey, uncertain: false, storefrontSlug });
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
          ...(storefrontSlug ? { storefront_slug: storefrontSlug } : {}),
          recipient,
          idempotency_key: requestKey,
          transaction_pin: transactionPin,
          ...(isAirtime || isElectricity ? { amount: purchaseAmount } : {}),
          ...(needsVerification ? {
            contact_phone: contactPhone,
            ...(isElectricity ? { meter_type: meterType } : {}),
            ...(isTv ? { subscription_type: 'change' } : {}),
          } : {}),
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
  const serviceTitle: Record<ServiceType, string> = {
    airtime: 'Buy airtime',
    data: 'Buy mobile data',
    'electricity-bill': 'Pay electricity bill',
    'tv-subscription': 'Subscribe to TV',
  };
  const serviceDescription: Record<ServiceType, string> = {
    airtime: 'Choose a mobile network, enter the phone number and the exact airtime amount you want, then confirm your purchase.',
    data: 'Choose a network and data plan, check the plan details and validity, then confirm the recipient number.',
    'electricity-bill': 'Choose your electricity provider, enter the meter details, verify the customer, and set the payment amount.',
    'tv-subscription': 'Choose your TV provider and new bouquet, enter the smartcard or decoder number, and verify the subscriber before payment. Bouquet renewal stays unavailable until the provider-verified renewal amount can be applied safely.',
  };

  return (
    <>
      <Head title={serviceTitle[serviceType]} />
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
                <h1 className="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">{serviceTitle[serviceType]}</h1>
                <p className="mt-2 text-sm leading-6 text-[var(--muted)]">{serviceDescription[serviceType]}</p>
              </div>
            </div>

            {scheduleMessage && <div role="status" className="mb-5 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm leading-6">{scheduleMessage}</div>}

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
                <div className="grid gap-5 sm:grid-cols-2">
                  <label className="block text-sm font-semibold">
                    {isAirtime ? 'Mobile network' : 'Provider'}
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
                    {isAirtime ? 'Network route option' : 'Product'}
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
                      {products.filter((product) => !selectedProduct || product.network === selectedProduct.network).map((product) => {
                        const siblings = products.filter((candidate) => sameCustomerPlan(candidate, product));
                        const siblingIndex = siblings.findIndex((candidate) => candidate.code === product.code);
                        const optionLabel = siblings.length > 1 ? `Option ${siblingIndex + 1}` : '';
                        return <option key={product.code} value={product.code}>{productLabel(product, optionLabel)}</option>;
                      })}
                    </select>
                    <span className="mt-1 block text-xs font-normal text-[var(--muted)]">Options are shown separately by plan and price. Provider details are not displayed.</span>
                  </label>
                </div>

                {beneficiaries.length > 0 && (
                  <label className="block text-sm font-semibold">Saved beneficiaries
                    <select value="" onChange={(event) => {
                      const saved = beneficiaries.find((item) => String(item.id) === event.target.value);
                      if (!saved) return;
                      setRecipient(saved.recipient);
                      if (saved.product_code && products.some((item) => item.code === saved.product_code && item.is_active)) setProductCode(saved.product_code);
                      if (saved.network) {
                        const matching = products.find((item) => item.network === saved.network && item.is_active);
                        if (matching) setProductCode(matching.code);
                      }
                      if (saved.service_details?.meter_type === 'prepaid' || saved.service_details?.meter_type === 'postpaid') setMeterType(saved.service_details.meter_type);
                      setVerification(null);
                      setRequestKey(newRequestKey());
                    }} disabled={submissionUncertain} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal">
                      <option value="">Choose a saved beneficiary…</option>
                      {beneficiaries.map((item) => <option key={item.id} value={item.id}>{item.label} · {item.recipient}</option>)}
                    </select>
                  </label>
                )}

                <label className="block text-sm font-semibold">
                  {isElectricity ? 'Meter number' : isTv ? 'TV smartcard / decoder number' : 'Recipient phone number'}
                  <input
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    value={recipient}
                    onChange={(event) => {
                      const nextRecipient = event.target.value;
                      setRecipient(nextRecipient);
                      setVerification(null);
                      setRequestKey(newRequestKey());
                      if (serviceType === 'airtime' || serviceType === 'data') {
                        const suggested = suggestNigerianNetwork(nextRecipient);
                        const matchingProduct = suggested
                          ? products.find((product) => product.is_active && networkMatchesLabel(product.network, suggested))
                          : undefined;
                        if (matchingProduct) setProductCode(matchingProduct.code);
                      }
                    }}
                    placeholder={isElectricity ? "Enter your electricity meter number" : isTv ? "Enter your TV smartcard number" : "e.g. 08012345678"}
                    maxLength={40}
                    required
                    disabled={submissionUncertain}
                    className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2"
                  />
                </label>

                {(serviceType === 'airtime' || serviceType === 'data') && detectedNetwork && (
                  <div role="status" className="rounded-xl border border-[var(--line)] bg-[var(--page)] p-3 text-sm">
                    <p className="font-semibold">Network prefix suggests {detectedNetwork}</p>
                    <p className="mt-1 text-xs leading-5 text-[var(--muted)]">
                      {suggestedNetworkProduct
                        ? `We've selected an available ${detectedNetwork} option. Please confirm the recipient's actual network before purchase.`
                        : `No active ${detectedNetwork} option is available right now. Choose the correct network manually if the number has been ported.`}
                      {' '}Number portability means a prefix cannot confirm the current network.
                    </p>
                  </div>
                )}

                <button type="button" disabled={submissionUncertain || savingBeneficiary || !recipient.trim()} onClick={async () => {
                  const label = window.prompt('Give this beneficiary a name (for example, Mum or Home TV):')?.trim();
                  if (!label) return;
                  setSavingBeneficiary(true); setError('');
                  try {
                    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
                    const response = await fetch('/wallet/service-beneficiaries', {
                      method: 'POST', credentials: 'same-origin',
                      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                      body: JSON.stringify({
                        service_type: serviceType, label, recipient,
                        network: selectedProduct?.network ?? null,
                        product_code: selectedProduct?.code ?? null,
                        service_details: isElectricity ? { meter_type: meterType } : (isTv ? { subscription_type: 'change' } : {}),
                      }),
                    });
                    const body = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(typeof body.message === 'string' ? body.message : 'Beneficiary could not be saved.');
                    setBeneficiaries((current) => [...current, body.data as SavedBeneficiary].sort((a, b) => a.label.localeCompare(b.label)));
                  } catch (cause: unknown) {
                    setError(cause instanceof Error ? cause.message : 'Beneficiary could not be saved.');
                  } finally { setSavingBeneficiary(false); }
                }} className="w-full rounded-xl border border-[var(--line)] px-4 py-2 text-sm font-semibold disabled:opacity-50">
                  {savingBeneficiary ? 'Saving beneficiary…' : 'Save recipient as beneficiary'}
                </button>

                {isAirtime && (
                  <label className="block text-sm font-semibold">Airtime amount (₦)
                    <input type="number" inputMode="numeric" min="1" max="1000000" step="1" value={purchaseAmount} onChange={(event) => { setPurchaseAmount(event.target.value); setRequestKey(newRequestKey()); }} required disabled={submissionUncertain} placeholder="Enter any whole-naira amount" className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)] focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2" />
                    <span className="mt-1 block text-xs font-normal text-[var(--muted)]">Enter the amount you want to buy; no preset price options. Whole naira amounts only.</span>
                  </label>
                )}

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
                          <input type="number" inputMode="decimal" min={electricityMinimum} max={electricityMaximum} step="1" value={purchaseAmount} onChange={(event) => { setPurchaseAmount(event.target.value); setRequestKey(newRequestKey()); }} required disabled={submissionUncertain} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal" placeholder="Enter purchase amount" />
                        </label>
                      </>
                    )}
                    <label className="block text-sm font-semibold">Contact phone number
                      <input type="tel" inputMode="tel" value={contactPhone} onChange={(event) => { setContactPhone(event.target.value); setVerification(null); }} required disabled={submissionUncertain} maxLength={20} placeholder="08012345678" className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal" />
                    </label>
                    <button type="button" disabled={submissionUncertain || verifying || !selectedProduct || !recipient.trim() || !contactPhone.trim() || (isElectricity && !purchaseAmount)} onClick={async () => {
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

                <div className="rounded-2xl border border-[var(--line)] p-4">
                  <label className="flex items-start gap-3 text-sm font-semibold">
                    <input type="checkbox" checked={scheduleMode} onChange={(event) => setScheduleMode(event.target.checked)} disabled={submissionUncertain} className="mt-1" />
                    <span>Schedule this purchase to repeat automatically
                      <span className="mt-1 block text-xs font-normal leading-5 text-[var(--muted)]">Each run uses the existing wallet ledger and provider safety checks. Uncertain results pause the schedule for review.</span>
                    </span>
                  </label>
                  {scheduleMode && (
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                      <label className="block text-sm font-semibold">Repeat
                        <select value={scheduleFrequency} onChange={(event) => setScheduleFrequency(event.target.value as 'daily' | 'weekly' | 'monthly')} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal">
                          <option value="daily">Every day</option><option value="weekly">Every week</option><option value="monthly">Every month</option>
                        </select>
                      </label>
                      <label className="block text-sm font-semibold">First run
                        <input type="datetime-local" required min={new Date(Date.now() + 60_000).toISOString().slice(0, 16)} value={scheduleNextRun} onChange={(event) => setScheduleNextRun(event.target.value)} className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-3 py-3 font-normal" />
                      </label>
                    </div>
                  )}
                </div>

                <label className="block text-sm font-semibold">
                  Transaction PIN
                  <input type="password" inputMode="numeric" autoComplete="off" value={transactionPin} onChange={(event) => setTransactionPin(event.target.value.replace(/\D/g, '').slice(0, 4))} disabled={submissionUncertain} placeholder="4 digits" maxLength={4} minLength={4} required className="mt-2 w-full rounded-xl border border-[var(--line)] bg-[var(--page)] px-4 py-3 font-normal outline-none focus:border-[var(--brand)]" />
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
                    {!selectedProduct.is_active && (
                      <div role="status" className="mt-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-sm leading-6 text-amber-700">
                        This VTpass product is listed for review, but purchases are not enabled yet. It will become available after sandbox verification and authorised catalogue approval. Your wallet will not be charged.
                      </div>
                    )}
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
                  disabled={submissionUncertain || submitting || loadingProducts || !selectedProduct || !selectedProduct.is_active || !recipient.trim() || (needsVerification && verification?.status !== 'verified') || (isElectricity && (displayedAmountMinor < electricityMinimum * 100 || displayedAmountMinor > electricityMaximum * 100)) || (isAirtime && (!purchaseAmount || displayedAmountMinor < 100 || displayedAmountMinor > 100000000 || Number(purchaseAmount) % 1 !== 0))}
                  className="flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand)] px-4 py-3.5 font-bold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {submitting && <LoaderCircle className="animate-spin" size={18} />}
                  {submitting ? 'Submitting securely…' : submissionUncertain ? 'Check existing order status above' : selectedProduct && !selectedProduct.is_active ? 'Purchases not enabled yet' : scheduleMode ? 'Save scheduled purchase' : selectedProduct ? `Continue for ${naira(displayedAmountMinor)}` : 'Choose a network'}
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
