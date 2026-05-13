<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Adapter\Api\Controller;

use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Communications\Application\GetTemplateOverrides\GetTemplateOverrides;
use DaemsModule\Communications\Application\GetTemplateOverrides\Input as GetInput;
use DaemsModule\Communications\Application\SaveTemplateOverrides\Input as SaveInput;
use DaemsModule\Communications\Application\SaveTemplateOverrides\SaveTemplateOverrides;
use DaemsModule\Communications\Domain\Mail\MailKind;

/**
 * Backstage template-overrides HTTP controller (Wave D Task D8).
 *
 * Routes (registered in modules/communications/backend/routes.php):
 *   GET /api/v1/backstage/communications/templates/{kind}/{locale} → show
 *   PUT /api/v1/backstage/communications/templates/{kind}/{locale} → update
 *
 * `{kind}` is the MailKind value (meeting_invitation | payment_reminder |
 * membership_approved | group_message). Newsletter has its own backstage
 * surface in Wave E; we still accept it here (the repo supports it) but the
 * primary UI is the templates list which lists the 4 strict kinds.
 *
 * `{locale}` is a SupportedLocale value (fi_FI | en_GB | sw_TZ).
 *
 * Whitelisted override keys are enforced by SaveTemplateOverrides — anything
 * outside the whitelist raises InvalidArgumentException which the Kernel
 * maps to 400.
 */
final class TemplatesController
{
    public function __construct(
        private readonly GetTemplateOverrides $getOverrides,
        private readonly SaveTemplateOverrides $saveOverrides,
    ) {
    }

    /**
     * @param array<string, string> $params route params: kind + locale
     */
    public function show(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $kind   = $this->parseKind($params['kind'] ?? '');
        $locale = $this->parseLocale($params['locale'] ?? '');

        $output = $this->getOverrides->execute(
            new GetInput($tenantId, $kind, $locale),
            $acting,
        );

        return Response::json([
            'data' => [
                'tenant_id' => $tenantId->value(),
                'kind'      => $kind->value,
                'locale'    => $locale->value(),
                'overrides' => $output->stringOverrides,
                'updated_at' => $output->updatedAt?->format(\DateTimeInterface::ATOM),
                'updated_by' => $output->updatedByUserId,
            ],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $acting   = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $kind   = $this->parseKind($params['kind'] ?? '');
        $locale = $this->parseLocale($params['locale'] ?? '');

        $overrides = $this->parseOverrides($request->arrayValue('overrides'));

        $output = $this->saveOverrides->execute(
            new SaveInput($tenantId, $kind, $locale, $overrides),
            $acting,
        );

        return Response::json([
            'data' => [
                'success'    => $output->success,
                'updated_at' => $output->updatedAt->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    private function parseKind(string $raw): MailKind
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('kind is required');
        }
        $kind = MailKind::tryFrom($raw);
        if ($kind === null) {
            throw new \InvalidArgumentException("Unknown mail kind: {$raw}");
        }
        return $kind;
    }

    private function parseLocale(string $raw): SupportedLocale
    {
        if ($raw === '') {
            throw new \InvalidArgumentException('locale is required');
        }
        return SupportedLocale::fromString($raw);
    }

    /**
     * @param array<array-key, mixed>|null $raw
     * @return array<string, string>
     */
    private function parseOverrides(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    "Override value for key '{$key}' must be a string.",
                );
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function resolveTenantId(Request $request, TenantId $fallback): TenantId
    {
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }
        return $fallback;
    }
}
