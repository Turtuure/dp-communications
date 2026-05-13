<?php

declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Adapter\Api\Controller;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use DaemsModule\Communications\Application\ListOutboxRows\Input as ListInput;
use DaemsModule\Communications\Application\ListOutboxRows\ListOutboxRows;
use DaemsModule\Communications\Application\RetryOutboxRow\Input as RetryInput;
use DaemsModule\Communications\Application\RetryOutboxRow\RetryOutboxRow;
use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\MailOutboxId;
use DaemsModule\Communications\Domain\Mail\MailOutboxRepositoryInterface;
use DaemsModule\Communications\Domain\Mail\MailOutboxStatus;

/**
 * Backstage outbox HTTP controller.
 *
 * Routes (registered in modules/communications/backend/routes.php):
 *   GET  /api/v1/backstage/communications/outbox          → index
 *   GET  /api/v1/backstage/communications/outbox/{id}     → show
 *   POST /api/v1/backstage/communications/outbox/{id}/retry → retry
 *
 * Authorisation is enforced inside the use cases (admin / moderator in the
 * row's tenant, or platform admin). Kernel maps ForbiddenException → 403,
 * \DomainException → 409, \InvalidArgumentException → 400 globally.
 */
final class OutboxController
{
    public function __construct(
        private readonly ListOutboxRows $listRows,
        private readonly RetryOutboxRow $retryRow,
        private readonly MailOutboxRepositoryInterface $outboxRepo,
    ) {
    }

    public function index(Request $request): Response
    {
        $acting = $request->requireActingUser();
        $tenantId = $this->resolveTenantId($request, $acting->activeTenant);

        $page    = max(1, (int) ($request->int('page', 1) ?? 1));
        $perPage = max(1, min(200, (int) ($request->int('per_page', 50) ?? 50)));

        $statusRaw = $request->string('status');
        $status    = ($statusRaw !== null && $statusRaw !== '')
            ? MailOutboxStatus::tryFrom($statusRaw)
            : null;

        $kindRaw = $request->string('kind');
        $kind    = ($kindRaw !== null && $kindRaw !== '')
            ? MailKind::tryFrom($kindRaw)
            : null;

        $from = $this->parseDate($request->string('from'));
        $to   = $this->parseDate($request->string('to'));

        $recipientSubstring = $request->string('recipient');
        if ($recipientSubstring !== null && trim($recipientSubstring) === '') {
            $recipientSubstring = null;
        }

        $input  = new ListInput(
            tenantId:           $tenantId,
            page:               $page,
            perPage:            $perPage,
            status:             $status,
            kind:               $kind,
            from:               $from,
            to:                 $to,
            recipientSubstring: $recipientSubstring,
        );

        $output = $this->listRows->execute($input, $acting);

        return Response::json([
            'data' => array_map(
                fn(MailOutbox $row): array => $this->serializeRow($row),
                $output->rows,
            ),
            'total'    => $output->totalCount,
            'page'     => $output->page,
            'per_page' => $output->perPage,
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $idRaw  = (string) ($params['id'] ?? '');

        $id = MailOutboxId::fromString($idRaw);
        $row = $this->outboxRepo->findById($id);
        if ($row === null) {
            throw new NotFoundException('outbox_row_not_found');
        }

        // Authorisation: same rules as list — admin/moderator in row's tenant,
        // or platform admin. ListOutboxRows uses the same check; mirror it
        // here so the show endpoint is consistent.
        if (!$acting->isPlatformAdmin()
            && !$acting->isAdminIn($row->tenantId)
            && $acting->roleIn($row->tenantId) !== \Daems\Domain\Tenant\UserTenantRole::Moderator
        ) {
            throw new ForbiddenException();
        }

        return Response::json(['data' => $this->serializeRow($row, includeBody: true)]);
    }

    /** @param array<string, string> $params */
    public function retry(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $idRaw  = (string) ($params['id'] ?? '');

        $id = MailOutboxId::fromString($idRaw);
        $output = $this->retryRow->execute(new RetryInput($id), $acting);

        return Response::json(['data' => ['success' => $output->success]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(MailOutbox $row, bool $includeBody = false): array
    {
        $data = [
            'id'                  => $row->id->value(),
            'tenant_id'           => $row->tenantId->value(),
            'kind'                => $row->kind->value,
            'category'            => $row->category->value,
            'recipient_email'     => $row->recipientEmail,
            'recipient_user_id'   => $row->recipientUserId?->value(),
            'locale'              => $row->locale->value(),
            'subject'             => $row->subject,
            'status'              => $row->status->value,
            'attempt_count'       => $row->attemptCount,
            'last_error'          => $row->lastError,
            'queued_at'           => $row->queuedAt->format(\DateTimeInterface::ATOM),
            'sent_at'             => $row->sentAt?->format(\DateTimeInterface::ATOM),
            'payload_meeting_id'    => $row->payloadMeetingId?->value(),
            'payload_invoice_id'    => $row->payloadInvoiceId?->value(),
            'payload_newsletter_id' => $row->payloadNewsletterId?->value(),
            'queued_by'           => $row->queuedBy->value(),
        ];
        if ($includeBody) {
            $data['body_html'] = $row->bodyHtml;
            $data['body_text'] = $row->bodyText;
        }
        return $data;
    }

    private function resolveTenantId(Request $request, TenantId $fallback): TenantId
    {
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant) {
            return $tenant->id;
        }
        return $fallback;
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            // Bad input → throw to surface as 400 via Kernel's InvalidArgumentException mapping
            throw new \InvalidArgumentException('Invalid date: ' . $raw);
        }
    }
}
