<?php
/**
 * Single source of truth for delivery statuses.
 *
 * deliveries.status is the ONLY status in the system. The orders table no
 * longer carries its own workflow status; every page (admin Orders, admin
 * Deliveries, the customer's My Orders, etc.) reads it from here / from
 * deliveries.status.
 *
 * Include from a page's top level (not inside a function):
 *     require_once '../config/delivery_status.php';
 */

$validStatuses = ['pending', 'out_for_delivery', 'delivered', 'failed', 'cancelled'];

// Display labels
$statusLabels = [
    'pending'          => 'Pending',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'failed'           => 'Failed',
    'cancelled'        => 'Cancelled',
];

// CSS classes: reuse the existing status-badge styles
// (out_for_delivery -> "processing", delivered -> "completed",
//  failed / cancelled -> "cancelled") so no new CSS is needed.
$statusClasses = [
    'pending'          => 'pending',
    'out_for_delivery' => 'processing',
    'delivered'        => 'completed',
    'failed'           => 'cancelled',
    'cancelled'        => 'cancelled',
];
