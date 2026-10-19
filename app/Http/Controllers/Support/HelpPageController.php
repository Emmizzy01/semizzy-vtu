<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HelpPageController extends Controller
{
    /**
     * These are the eight support destinations shown in the landing-page footer.
     * Copy is intentionally stored in IntegrationSettings so admins can maintain it
     * without a deployment or schema change.
     */
    public static function definitions(): array
    {
        return [
            'help-center' => ['label' => 'Help Center', 'icon' => '🧭', 'title' => 'How can we help you?', 'intro' => 'Find clear guidance for using Semizzy safely and confidently.', 'body' => "Start with the topic that matches what you need. You can learn how to use services, manage your account and find help when something does not look right.\n\nFor account-specific assistance, sign in and open a support ticket so our team can follow up securely.", 'image' => 'https://images.unsplash.com/photo-1553877522-43269d4ea984?auto=format&fit=crop&w=1400&q=85'],
            'contact-us' => ['label' => 'Contact Us', 'icon' => '💬', 'title' => 'Contact Semizzy', 'intro' => 'Reach the Semizzy team through our official support channels.', 'body' => "For help with an order, include the transaction reference and the service involved. Never share your password, one-time password or transaction PIN.\n\nUse the support ticket area after signing in for account-specific issues and keep your ticket reference for follow-up.", 'image' => 'https://images.unsplash.com/photo-1521737711867-e3b97375f902?auto=format&fit=crop&w=1400&q=85'],
            'live-chat' => ['label' => 'Live Chat', 'icon' => '🗨️', 'title' => 'Chat with support', 'intro' => 'Get help with a Semizzy question or an existing request.', 'body' => "When live chat is available, use the official chat entry point shown by Semizzy. If chat is offline, open a support ticket with a short description of the issue and any relevant transaction reference.\n\nSupport will never ask you to disclose your password, OTP or transaction PIN.", 'image' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1400&q=85'],
            'faqs' => ['label' => 'FAQs', 'icon' => '❔', 'title' => 'Frequently asked questions', 'intro' => 'Quick answers about payments, services and account safety.', 'body' => "Wallet funding: use the funding options currently shown in your account and follow the instructions displayed there.\n\nOrders: check your transaction history for the latest recorded status before trying again. If a transaction is pending, contact support rather than submitting a duplicate purchase.\n\nSecurity: never share your password, OTP or transaction PIN with anyone.", 'image' => 'https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?auto=format&fit=crop&w=1400&q=85'],
            'transaction-status' => ['label' => 'Transaction Status', 'icon' => '🧾', 'title' => 'Check a transaction', 'intro' => 'Understand the status of a Semizzy payment or service order.', 'body' => "Open your dashboard and transaction history to review the status and reference of your order. A pending or unclear provider response does not mean you should immediately submit the same order again.\n\nIf the status remains unresolved, contact support with the transaction reference. Do not share sensitive authentication codes.", 'image' => 'https://images.unsplash.com/photo-1554224155-6726b3ff858f?auto=format&fit=crop&w=1400&q=85'],
            'how-to-fund-wallet' => ['label' => 'How to Fund Wallet', 'icon' => '👛', 'title' => 'Fund your Semizzy wallet', 'intro' => 'Follow the funding instructions displayed in your account.', 'body' => "1. Sign in to your Semizzy account.\n2. Open Wallet and choose an available funding method.\n3. Follow the on-screen payment or deposit instructions exactly.\n4. Check your wallet and transaction history for the recorded result.\n\nFor manual deposits, submit the required evidence through the provided workflow and wait for review. Never rely on a screenshot alone as proof that a deposit has been credited.", 'image' => 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?auto=format&fit=crop&w=1400&q=85'],
            'report-issue' => ['label' => 'Report Issue', 'icon' => '🛟', 'title' => 'Report a problem', 'intro' => 'Tell us what happened so the team can investigate safely.', 'body' => "Describe the service, approximate time, transaction reference and the result you expected. Include only information needed to investigate the issue.\n\nNever attach passwords, transaction PINs, OTPs or full payment-card details. If money may have been charged, avoid repeating the transaction until its status has been checked.", 'image' => 'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=1400&q=85'],
            '24-7-support' => ['label' => '24/7 Support', 'icon' => '🕒', 'title' => 'Support availability', 'intro' => 'Find the right route to get assistance with your Semizzy account.', 'body' => "You can submit a support request whenever the support form is available. Response times may vary by issue type and service availability; a request submission is not a promise of an immediate live response.\n\nFor urgent transaction issues, include the transaction reference and avoid making a duplicate purchase while the original status is being checked.", 'image' => 'https://images.unsplash.com/photo-1521791136064-7986c2920216?auto=format&fit=crop&w=1400&q=85'],
        ];
    }

    public function show(string $slug, IntegrationSettings $settings): InertiaResponse
    {
        $definitions = self::definitions();
        abort_unless(isset($definitions[$slug]), 404);
        $page = $this->pageContent($slug, $definitions[$slug], $settings);

        return Inertia::render('Support/HelpPage', [
            'page' => $page,
            'supportLinks' => collect($definitions)->map(fn (array $definition, string $key) => [
                'slug' => $key, 'label' => $definition['label'], 'icon' => (string) $settings->get("support_page.{$key}.icon", $definition['icon']),
            ])->values(),
        ]);
    }

    public function adminEdit(IntegrationSettings $settings): InertiaResponse
    {
        $pages = [];
        foreach (self::definitions() as $slug => $definition) {
            $pages[] = ['slug' => $slug] + $this->pageContent($slug, $definition, $settings);
        }

        return Inertia::render('Admin/SupportPages', ['pages' => $pages]);
    }

    public function adminUpdate(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'pages' => ['required', 'array', 'size:8'],
            'pages.*.slug' => ['required', 'string', 'in:help-center,contact-us,live-chat,faqs,transaction-status,how-to-fund-wallet,report-issue,24-7-support'],
            'pages.*.title' => ['required', 'string', 'max:140'],
            'pages.*.intro' => ['required', 'string', 'max:280'],
            'pages.*.body' => ['required', 'string', 'max:6000'],
            'pages.*.icon' => ['required', 'string', 'max:16'],
            'pages.*.image' => ['required', 'url', 'starts_with:https://', 'max:2048'],
        ]);

        $definitions = self::definitions();
        DB::transaction(function () use ($request, $settings, $data, $definitions): void {
            foreach ($data['pages'] as $page) {
                $slug = $page['slug'];
                foreach (['title', 'intro', 'body', 'icon', 'image'] as $field) {
                    $key = "support_page.{$slug}.{$field}";
                    $old = (string) $settings->get($key, $definitions[$slug][$field]);
                    $new = trim((string) $page[$field]);
                    if (hash_equals(hash('sha256', $old), hash('sha256', $new))) {
                        continue;
                    }
                    $settings->set($key, $new, (int) $request->user()->id);
                    PlatformSettingAudit::query()->create([
                        'actor_user_id' => $request->user()->id,
                        'setting_key' => $key,
                        'old_value_hash' => hash('sha256', $old),
                        'new_value_hash' => hash('sha256', $new),
                        'reason' => trim($data['reason']),
                        'created_at' => now(),
                    ]);
                }
            }
        }, attempts: 3);

        return back()->with('status', 'Support pages saved. Content changes are recorded in the platform audit trail.');
    }

    private function pageContent(string $slug, array $definition, IntegrationSettings $settings): array
    {
        $page = ['slug' => $slug, 'label' => $definition['label']];
        foreach (['icon', 'title', 'intro', 'body', 'image'] as $field) {
            $page[$field] = (string) $settings->get("support_page.{$slug}.{$field}", $definition[$field]);
        }

        return $page;
    }
}
