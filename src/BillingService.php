<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use RuntimeException;
use Throwable;

final class BillingService
{
    public function __construct(private PDO $db) {}

    public function createHostFeeInvoice(
        int $seasonId,
        int $amountCents,
        ?string $dueDate,
        ?string $description,
        ?int $createdByUserId
    ): array {
        if ($seasonId < 1) {
            throw new RuntimeException('Valid season_id is required.');
        }
        if ($amountCents < 100 || $amountCents > 5000000) {
            throw new RuntimeException('Host fee must be between $1.00 and $50,000.00.');
        }
        if ($dueDate !== null && !$this->validDate($dueDate)) {
            throw new RuntimeException('due_date must use YYYY-MM-DD format.');
        }

        $this->db->beginTransaction();
        try {
            $seasonStmt = $this->db->prepare('SELECT s.id,s.venue_id,s.name,v.name venue_name FROM league_seasons s JOIN venues v ON v.id=s.venue_id WHERE s.id=:id FOR UPDATE');
            $seasonStmt->execute(['id' => $seasonId]);
            $season = $seasonStmt->fetch();
            if (!$season) {
                throw new RuntimeException('League season not found.');
            }

            $existing = $this->db->prepare("SELECT vi.id FROM venue_invoices vi WHERE vi.season_id=:season_id AND vi.invoice_type='league_host_fee' AND vi.status IN('open','paid') LIMIT 1 FOR UPDATE");
            $existing->execute(['season_id' => $seasonId]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('This league season already has an active host-fee invoice.');
            }

            $order = $this->db->prepare("INSERT INTO orders(player_id,season_id,order_type,subtotal_cents,currency,status,created_at,updated_at) VALUES(NULL,:season_id,'league_host_fee',:amount_cents,'USD','pending',NOW(),NOW())");
            $order->execute([
                'season_id' => $seasonId,
                'amount_cents' => $amountCents,
            ]);
            $orderId = (int)$this->db->lastInsertId();

            $invoice = $this->db->prepare("INSERT INTO venue_invoices(venue_id,season_id,order_id,invoice_type,amount_cents,currency,status,due_date,description,created_by_user_id,created_at,updated_at) VALUES(:venue_id,:season_id,:order_id,'league_host_fee',:amount_cents,'USD','open',:due_date,:description,:created_by_user_id,NOW(),NOW())");
            $invoice->execute([
                'venue_id' => (int)$season['venue_id'],
                'season_id' => $seasonId,
                'order_id' => $orderId,
                'amount_cents' => $amountCents,
                'due_date' => $dueDate,
                'description' => trim((string)$description) ?: 'FourBag league host fee',
                'created_by_user_id' => $createdByUserId ?: null,
            ]);
            $invoiceId = (int)$this->db->lastInsertId();
            $this->db->commit();

            return $this->invoiceById($invoiceId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function venueInvoices(int $venueId): array
    {
        if ($venueId < 1) {
            throw new RuntimeException('Valid venue_id is required.');
        }
        $stmt = $this->db->prepare("SELECT vi.id,vi.venue_id,vi.season_id,vi.order_id,vi.invoice_type,vi.amount_cents,vi.currency,vi.status,vi.due_date,vi.description,vi.paid_at,vi.created_at,vi.updated_at,s.name season_name,o.status order_status FROM venue_invoices vi LEFT JOIN league_seasons s ON s.id=vi.season_id JOIN orders o ON o.id=vi.order_id WHERE vi.venue_id=:venue_id ORDER BY FIELD(vi.status,'open','paid','void'),vi.due_date IS NULL,vi.due_date,vi.id DESC");
        $stmt->execute(['venue_id' => $venueId]);
        return $stmt->fetchAll();
    }

    public function invoiceById(int $invoiceId): array
    {
        $stmt = $this->db->prepare("SELECT vi.id,vi.venue_id,vi.season_id,vi.order_id,vi.invoice_type,vi.amount_cents,vi.currency,vi.status,vi.due_date,vi.description,vi.paid_at,vi.created_at,vi.updated_at,s.name season_name,v.name venue_name,o.status order_status FROM venue_invoices vi JOIN venues v ON v.id=vi.venue_id LEFT JOIN league_seasons s ON s.id=vi.season_id JOIN orders o ON o.id=vi.order_id WHERE vi.id=:id LIMIT 1");
        $stmt->execute(['id' => $invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) {
            throw new RuntimeException('Host-fee invoice not found.');
        }
        return $invoice;
    }

    public function venueIdForInvoice(int $invoiceId): int
    {
        $stmt = $this->db->prepare('SELECT venue_id FROM venue_invoices WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $invoiceId]);
        $venueId = $stmt->fetchColumn();
        if ($venueId === false) {
            throw new RuntimeException('Host-fee invoice not found.');
        }
        return (int)$venueId;
    }

    public function orderIdForInvoice(int $invoiceId): int
    {
        $stmt = $this->db->prepare("SELECT order_id FROM venue_invoices WHERE id=:id AND status='open' LIMIT 1");
        $stmt->execute(['id' => $invoiceId]);
        $orderId = $stmt->fetchColumn();
        if ($orderId === false) {
            throw new RuntimeException('Open host-fee invoice not found.');
        }
        return (int)$orderId;
    }

    private function validDate(string $date): bool
    {
        $parts = date_parse_from_format('Y-m-d', $date);
        return $parts['error_count'] === 0
            && $parts['warning_count'] === 0
            && sprintf('%04d-%02d-%02d', $parts['year'], $parts['month'], $parts['day']) === $date;
    }
}
