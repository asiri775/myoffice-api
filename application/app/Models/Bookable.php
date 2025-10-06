<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Base class for all bookables (Space, Hotel, Car, Event, Flight, …)
 */
abstract class Bookable extends Model
{
    /** Override in child (e.g. 'space') */
    public string $type = '';

    protected $guarded = [];
    public $timestamps = true;

  
    public function isBookable(): bool
    {
        $status = $this->getAttribute('status');
        return $status === 'publish' || $status === 'active' || $status === 1;
    }

    /**
     * Shared lightweight service-fee calculator.
     * $fees format: [['unit' => 'fixed'|'percent', 'price' => 10, 'name' => 'Service Fee'], ...]
     */
    protected function calculateServiceFees(?array $fees, float $base, int $qty = 1): float
    {
        $total = 0.0;
        if (!$fees || !is_array($fees)) return 0.0;

        foreach ($fees as $fee) {
            $unit  = $fee['unit']  ?? 'fixed';
            $price = floatval($fee['price'] ?? 0);
            $row   = $unit === 'percent' ? ($base * $price / 100) : $price;
            // some systems multiply by qty (tickets/guests). keep it simple: add row * 1
            $total += $row;
        }
        return round($total, 2);
    }

    /**
     * Minimal validator hook used by child addToCart() methods.
     * Return true or throw \InvalidArgumentException to stop.
     */
    protected function addToCartValidate(Request $r): bool
    {
        if (!$this->isBookable()) {
            throw new \InvalidArgumentException('Service is not bookable');
        }
        if (!$r->filled('service_id') || !$r->filled('service_type')) {
            throw new \InvalidArgumentException('Missing service_id/service_type');
        }
        return true;
    }

    /** Each bookable must implement its own addToCart(Request $request). */
    abstract public function addToCart(Request $request);
}
