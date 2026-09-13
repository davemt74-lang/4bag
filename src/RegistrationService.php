<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use RuntimeException;
use Throwable;

final class RegistrationService
{
    public function __construct(private PDO $db) {}

    public function register(array $input): array
    {
        $seasonId = (int)($input['season_id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if ($seasonId < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('season_id, player name and valid email are required.');
        }

        $this->db->beginTransaction();
        try {
            $seasonStmt = $this->db->prepare('SELECT id,team_limit,players_per_team,registration_fee_cents,status FROM league_seasons WHERE id=:id FOR UPDATE');
            $seasonStmt->execute(['id' => $seasonId]);
            $season = $seasonStmt->fetch();
            if (!$season) {
                throw new RuntimeException('League season not found.');
            }
            if ($season['status'] !== 'registration_open') {
                throw new RuntimeException('This league is not accepting registrations.');
            }

            $playerStmt = $this->db->prepare("INSERT INTO players(name,email,created_at,updated_at) VALUES(:name,:email,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()");
            $playerStmt->execute(['name' => $name, 'email' => $email]);
            $findPlayer = $this->db->prepare('SELECT id FROM players WHERE email=:email LIMIT 1');
            $findPlayer->execute(['email' => $email]);
            $playerId = (int)$findPlayer->fetchColumn();

            $existingStmt = $this->db->prepare('SELECT id,board_purchase,registration_fee_cents,payment_status,registration_credit_order_id FROM registrations WHERE season_id=:season_id AND player_id=:player_id LIMIT 1 FOR UPDATE');
            $existingStmt->execute(['season_id' => $seasonId, 'player_id' => $playerId]);
            $existing = $existingStmt->fetch();

            if (!$existing) {
                $count = $this->db->prepare('SELECT COUNT(*) FROM registrations WHERE season_id=:season_id');
                $count->execute(['season_id' => $seasonId]);
                $capacity = (int)$season['team_limit'] * (int)$season['players_per_team'];
                if ((int)$count->fetchColumn() >= $capacity) {
                    throw new RuntimeException('This league is full.');
                }
            }

            $joinType = (string)($input['join_type'] ?? 'solo');
            if (!in_array($joinType, ['solo', 'friends', 'team'], true)) {
                $joinType = 'solo';
            }
            $requestedGroup = trim((string)($input['requested_group'] ?? '')) ?: null;
            $boardRequested = !empty($input['board_purchase']);

            $boardPurchase = 0;
            $fee = (int)$season['registration_fee_cents'];
            $paymentStatus = 'pending';
            $creditOrderId = null;
            $orderId = null;
            $checkoutToken = null;

            if ($existing) {
                $priorStatus = (string)$existing['payment_status'];
                if ($priorStatus === 'included_with_board') {
                    $boardPurchase = 1;
                    $fee = 0;
                    $paymentStatus = 'included_with_board';
                    $creditOrderId = $existing['registration_credit_order_id'] ? (int)$existing['registration_credit_order_id'] : null;
                    $orderId = $creditOrderId;
                    $boardRequested = false;
                } elseif (in_array($priorStatus, ['paid', 'waived'], true)) {
                    if ($boardRequested && !(bool)$existing['board_purchase']) {
                        throw new RuntimeException('A paid or waived league registration cannot be converted to a board-purchase credit. Buy the board separately.');
                    }
                    $boardPurchase = (int)$existing['board_purchase'];
                    $fee = (int)$existing['registration_fee_cents'];
                    $paymentStatus = $priorStatus;
                    $creditOrderId = $existing['registration_credit_order_id'] ? (int)$existing['registration_credit_order_id'] : null;
                    $orderId = $creditOrderId;
                    $boardRequested = false;
                } elseif ($priorStatus === 'refunded') {
                    throw new RuntimeException('Refunded registrations must be reactivated by an operator.');
                }
            }

            if ($boardRequested) {
                $order = $this->findBoardOrder($playerId, $seasonId);
                if (!$order) {
                    $checkoutToken = bin2hex(random_bytes(32));
                    $createOrder = $this->db->prepare("INSERT INTO orders(player_id,season_id,order_type,subtotal_cents,currency,status,checkout_token_hash,created_at,updated_at) VALUES(:player_id,:season_id,'fourbag_set',19900,'USD','pending',:checkout_token_hash,NOW(),NOW())");
                    $createOrder->execute([
                        'player_id' => $playerId,
                        'season_id' => $seasonId,
                        'checkout_token_hash' => hash('sha256', $checkoutToken),
                    ]);
                    $orderId = (int)$this->db->lastInsertId();
                    $orderStatus = 'pending';
                } else {
                    $orderId = (int)$order['id'];
                    $orderStatus = (string)$order['status'];
                    if ($orderStatus === 'pending') {
                        $checkoutToken = bin2hex(random_bytes(32));
                        $this->db->prepare('UPDATE orders SET checkout_token_hash=:checkout_token_hash,updated_at=NOW() WHERE id=:id')->execute([
                            'checkout_token_hash' => hash('sha256', $checkoutToken),
                            'id' => $orderId,
                        ]);
                    }
                }

                $orderIsPaid = in_array($orderStatus, ['paid', 'fulfilled'], true);
                $boardPurchase = $orderIsPaid ? 1 : 0;
                $fee = 0;
                $creditOrderId = $orderId;
                $paymentStatus = $orderIsPaid ? 'included_with_board' : 'awaiting_board_payment';
            } elseif ($existing && $existing['payment_status'] === 'awaiting_board_payment' && $existing['registration_credit_order_id']) {
                $cancel = $this->db->prepare("UPDATE orders SET status='cancelled',checkout_token_hash=NULL,updated_at=NOW() WHERE id=:id AND status='pending'");
                $cancel->execute(['id' => (int)$existing['registration_credit_order_id']]);
                $boardPurchase = 0;
                $fee = (int)$season['registration_fee_cents'];
                $paymentStatus = 'pending';
                $creditOrderId = null;
                $orderId = null;
                $checkoutToken = null;
            }

            $registration = $this->db->prepare("INSERT INTO registrations(season_id,player_id,join_type,requested_group,board_purchase,registration_fee_cents,payment_status,registration_credit_order_id,created_at,updated_at) VALUES(:season_id,:player_id,:join_type,:requested_group,:board_purchase,:fee,:payment_status,:credit_order_id,NOW(),NOW()) ON DUPLICATE KEY UPDATE join_type=VALUES(join_type),requested_group=VALUES(requested_group),board_purchase=VALUES(board_purchase),registration_fee_cents=VALUES(registration_fee_cents),payment_status=VALUES(payment_status),registration_credit_order_id=VALUES(registration_credit_order_id),updated_at=NOW()");
            $registration->execute([
                'season_id' => $seasonId,
                'player_id' => $playerId,
                'join_type' => $joinType,
                'requested_group' => $requestedGroup,
                'board_purchase' => $boardPurchase,
                'fee' => $fee,
                'payment_status' => $paymentStatus,
                'credit_order_id' => $creditOrderId,
            ]);

            $this->db->commit();
            return [
                'player_id' => $playerId,
                'season_id' => $seasonId,
                'payment_status' => $paymentStatus,
                'registration_fee_cents' => $fee,
                'board_order_id' => $orderId,
                'board_order_amount_cents' => $orderId ? 19900 : null,
                'checkout_token' => $checkoutToken,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function completeBoardOrder(int $orderId): array
    {
        if ($orderId < 1) {
            throw new RuntimeException('Valid order_id is required.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM orders WHERE id=:id AND order_type='fourbag_set' FOR UPDATE");
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new RuntimeException('FourBag board order not found.');
            }
            if (in_array($order['status'], ['cancelled', 'refunded'], true)) {
                throw new RuntimeException('Cancelled or refunded board orders cannot provide league registration credit.');
            }
            if (!$order['player_id'] || !$order['season_id']) {
                throw new RuntimeException('Board order is not linked to a player and season.');
            }

            $registrationStmt = $this->db->prepare('SELECT id,registration_credit_order_id,payment_status FROM registrations WHERE player_id=:player_id AND season_id=:season_id FOR UPDATE');
            $registrationStmt->execute([
                'player_id' => (int)$order['player_id'],
                'season_id' => (int)$order['season_id'],
            ]);
            $registration = $registrationStmt->fetch();
            if (!$registration) {
                throw new RuntimeException('Matching league registration was not found for this board order.');
            }
            if ((int)($registration['registration_credit_order_id'] ?? 0) !== $orderId) {
                throw new RuntimeException('This board order is not assigned as the registration credit source.');
            }

            if ($order['status'] === 'pending') {
                $this->db->prepare("UPDATE orders SET status='paid',checkout_token_hash=NULL,updated_at=NOW() WHERE id=:id")->execute(['id' => $orderId]);
                $orderStatus = 'paid';
            } else {
                $orderStatus = (string)$order['status'];
            }

            $update = $this->db->prepare("UPDATE registrations SET board_purchase=1,registration_fee_cents=0,payment_status='included_with_board',registration_credit_order_id=:order_id,updated_at=NOW() WHERE id=:registration_id");
            $update->execute([
                'order_id' => $orderId,
                'registration_id' => (int)$registration['id'],
            ]);

            $this->db->commit();
            return [
                'order_id' => $orderId,
                'player_id' => (int)$order['player_id'],
                'season_id' => (int)$order['season_id'],
                'order_status' => $orderStatus,
                'registration_status' => 'included_with_board',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function findBoardOrder(int $playerId, int $seasonId): ?array
    {
        $stmt = $this->db->prepare("SELECT id,status FROM orders WHERE player_id=:player_id AND season_id=:season_id AND order_type='fourbag_set' AND status NOT IN('cancelled','refunded') ORDER BY id DESC LIMIT 1");
        $stmt->execute(['player_id' => $playerId, 'season_id' => $seasonId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
