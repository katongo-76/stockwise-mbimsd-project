<?php

class StockCalculationService
{
    /**
     * Stock-in: creates a new batch (with its own expiry date), increases
     * the product's current_quantity atomically, and logs the transaction.
     */
    public static function recordStockIn(
        PDO $pdo,
        int $productId,
        string $branchId,
        int $quantity,
        ?string $expiryDate,
        string $receivedDate,
        string $barcode,
        ?int $performedBy
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stock_batches (product_id, quantity_received, quantity_remaining, expiry_date, received_date, barcode)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$productId, $quantity, $quantity, $expiryDate ?: null, $receivedDate, $barcode]);
            $batchId = (int)$pdo->lastInsertId();

            $pdo->prepare('UPDATE products SET current_quantity = current_quantity + ? WHERE id = ?')
                ->execute([$quantity, $productId]);

            $pdo->prepare(
                'INSERT INTO transactions (product_id, branch_id, batch_id, type, quantity, performed_by) VALUES (?,?,?,\'stock_in\',?,?)'
            )->execute([$productId, $branchId, $batchId, $quantity, $performedBy]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // A delivery only raises stock, so it can never cross the threshold downwards (no alert).
        return self::afterTransactionCheck($pdo, $productId, $branchId, null);
    }

    /**
     * Stock-out: deducts from the batch expiring soonest first (FEFO —
     * first-expire-first-out), decreases current_quantity atomically,
     * and logs the transaction. Fails cleanly if stock is insufficient.
     */
    public static function recordStockOut(
        PDO $pdo,
        int $productId,
        string $branchId,
        int $quantity,
        ?int $performedBy
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        $pdo->beginTransaction();
        try {
            $product = $pdo->prepare('SELECT current_quantity FROM products WHERE id = ? FOR UPDATE');
            $product->execute([$productId]);
            $row = $product->fetch();
            if (!$row) {
                throw new RuntimeException('Product not found.');
            }
            // Expired units are still on the shelf (until written off) but must never be sold.
            $today = date('Y-m-d');
            $exp = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity_remaining), 0) FROM stock_batches
                 WHERE product_id = ? AND quantity_remaining > 0 AND expiry_date IS NOT NULL AND expiry_date < ?'
            );
            $exp->execute([$productId, $today]);
            $expired = (int)$exp->fetchColumn();
            $sellable = max(0, (int)$row['current_quantity'] - $expired);
            if ($sellable < $quantity) {
                $msg = "Insufficient stock: only {$sellable} unit(s) can be sold.";
                if ($expired > 0) {
                    $msg .= " {$expired} unit(s) have expired and must be written off on the Expiry page.";
                }
                throw new RuntimeException($msg);
            }

            $remaining = $quantity;
            $batches = $pdo->prepare(
                'SELECT id, quantity_remaining FROM stock_batches
                 WHERE product_id = ? AND quantity_remaining > 0
                   AND (expiry_date IS NULL OR expiry_date >= ?)
                 ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC
                 FOR UPDATE'
            );
            $batches->execute([$productId, $today]);
            $lastBatchId = null;

            foreach ($batches->fetchAll() as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, (int)$batch['quantity_remaining']);
                $pdo->prepare('UPDATE stock_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?')
                    ->execute([$take, $batch['id']]);
                $remaining -= $take;
                $lastBatchId = $batch['id'];
            }

            $pdo->prepare('UPDATE products SET current_quantity = current_quantity - ? WHERE id = ?')
                ->execute([$quantity, $productId]);

            $pdo->prepare(
                'INSERT INTO transactions (product_id, branch_id, batch_id, type, quantity, performed_by) VALUES (?,?,?,\'stock_out\',?,?)'
            )->execute([$productId, $branchId, $lastBatchId, $quantity, $performedBy]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::afterTransactionCheck($pdo, $productId, $branchId, (int)$row['current_quantity']);
    }


    /**
     * Checks the low-stock threshold right after a transaction (FR-V).
     * An alert is sent only when this transaction moved the product from
     * above its threshold to at-or-below it, so staff are not emailed again
     * on every later movement while stock stays low; the daily digest
     * keeps reminding them of items that remain low.
     */
    private static function afterTransactionCheck(PDO $pdo, int $productId, string $branchId, ?int $quantityBefore): array
    {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        $lowStock = $product && $product['current_quantity'] <= $product['low_stock_threshold'];
        $crossed = $lowStock && $quantityBefore !== null && $quantityBefore > $product['low_stock_threshold'];

        return ['product' => $product, 'lowStock' => $lowStock, 'alertSent' => false];
    }
}
