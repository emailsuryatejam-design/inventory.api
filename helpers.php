<?php
/**
 * KCL Stores — Shared Helpers
 */

/**
 * Generate a document number like ORD-NGO-2602-0001
 * @param PDO $pdo
 * @param string $prefix  e.g. 'ORD', 'DSP', 'RCV', 'ISS'
 * @param string $campCode e.g. 'NGO', 'SER'
 * @return string
 */
function generateDocNumber(PDO $pdo, string $prefix, string $campCode): string
{
    $year = date('y');
    $month = (int) date('m');
    $yearMonth = $year . str_pad($month, 2, '0', STR_PAD_LEFT);

    // Lock row and increment atomically
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT last_number FROM number_sequences
            WHERE prefix = ? AND camp_code = ? AND current_year = YEAR(NOW()) AND current_month = ?
            FOR UPDATE
        ");
        $stmt->execute([$prefix, $campCode, $month]);
        $row = $stmt->fetch();

        if ($row) {
            $next = (int) $row['last_number'] + 1;
            $pdo->prepare("
                UPDATE number_sequences SET last_number = ?
                WHERE prefix = ? AND camp_code = ? AND current_year = YEAR(NOW()) AND current_month = ?
            ")->execute([$next, $prefix, $campCode, $month]);
        } else {
            $next = 1;
            $pdo->prepare("
                INSERT INTO number_sequences (prefix, camp_code, current_year, current_month, last_number)
                VALUES (?, ?, YEAR(NOW()), ?, 1)
            ")->execute([$prefix, $campCode, $month]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return "{$prefix}-{$campCode}-{$yearMonth}-" . str_pad($next, 4, '0', STR_PAD_LEFT);
}

/**
 * Validate an order line against stock levels and rules
 * Returns: ['status' => 'clear|review|flagged', 'note' => '...']
 */
function validateOrderLine(array $line, float $campStock, float $hoStock, ?float $parLevel, ?float $avgDailyUsage): array
{
    $qty = (float) $line['qty'];
    $notes = [];
    $status = 'clear';

    // Check 1: Ordering more than par level
    if ($parLevel && $qty > $parLevel * 1.5) {
        $status = 'flagged';
        $notes[] = "Qty ({$qty}) exceeds 150% of par ({$parLevel})";
    }

    // Check 2: Camp has enough stock already
    if ($campStock > 0 && $parLevel && $campStock >= $parLevel * 0.8) {
        $status = ($status === 'flagged') ? 'flagged' : 'review';
        $notes[] = "Camp stock ({$campStock}) already at " . round($campStock / $parLevel * 100) . "% of par";
    }

    // Check 3: HO has low stock
    if ($hoStock <= 0) {
        $status = 'flagged';
        $notes[] = "HO is out of stock";
    } elseif ($hoStock < $qty) {
        $status = ($status === 'flagged') ? 'flagged' : 'review';
        $notes[] = "HO stock ({$hoStock}) less than requested ({$qty})";
    }

    return [
        'status' => $status,
        'note' => implode('; ', $notes) ?: null,
    ];
}
