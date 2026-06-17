<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SimularUberOrder extends Command
{
    protected $signature = 'simular:uber-order {--discount_type= : UBER_CREATED o MC_CREATED}';

    protected $description = 'Simula el procesamiento de un pedido de Uber y muestra los datos como quedarían en el POS';

    public function handle()
    {
        $jsonPath = base_path('pedido.json');

        if (!file_exists($jsonPath)) {
            $this->error('No se encontró el archivo pedido.json en la raíz del proyecto');
            return 1;
        }

        $json = json_decode(file_get_contents($jsonPath));

        if (!isset($json->order)) {
            $this->error('El archivo pedido.json no tiene la estructura esperada');
            return 1;
        }

        $order = $json->order;

        $discountTypes = $this->option('discount_type')
            ? [$this->option('discount_type')]
            : ['UBER_CREATED', 'MC_CREATED'];

        foreach ($discountTypes as $discountType) {
            $this->newLine();
            $this->info('========================================');
            $this->info("  discount_type: $discountType");
            $this->info('========================================');
            $this->newLine();

            $this->simulate($order, $discountType);
        }

        return 0;
    }

    private function simulate($order, $discountType)
    {
        $useUberDiscountCalc = ($discountType === 'UBER_CREATED');

        $priceBreakdownMap = [];
        if ($useUberDiscountCalc && isset($order->payment->payment_detail->item_charges->price_breakdown)) {
            foreach ($order->payment->payment_detail->item_charges->price_breakdown as $pb) {
                if (isset($pb->discount->total->gross->amount_e5)) {
                    $priceBreakdownMap[$pb->cart_item_id] = [
                        'discount_gross' => $pb->discount->total->gross->amount_e5 / 100000,
                        'quantity' => $pb->quantity->amount,
                    ];
                }
            }
        }

        $promoItem = [];
        $promoSubTotal = [];

        if (isset($order->payment->payment_detail->promotions->details) && count($order->payment->payment_detail->promotions->details)) {
            foreach ($order->payment->payment_detail->promotions->details as $itemPromo) {
                if (is_array($itemPromo->discount_items) && count($itemPromo->discount_items)) {
                    foreach ($itemPromo->discount_items as $item) {
                        $promoItem[] = [
                            'external_promotion_id' => $itemPromo->external_promotion_id ?? $itemPromo->promotion_uuid,
                            'type' => $itemPromo->type,
                            'external_id' => $item->external_id,
                            'discounted_quantity' => $item->discounted_quantity,
                            'discount_amount_applied' => number_format((($item->discount_amount_applied * -1) / 100), 2, '.', ''),
                        ];
                    }
                } else {
                    $promoSubTotal[] = [
                        'external_promotion_id' => $itemPromo->external_promotion_id ?? $itemPromo->promotion_uuid,
                        'type' => $itemPromo->type,
                        'discount_value' => number_format((($itemPromo->discount_value * -1) / 100), 2, '.', ''),
                    ];
                }
            }
        }

        $items = [];

        if (isset($order->carts) && isset($order->carts[0]->items)) {
            foreach ($order->carts[0]->items as $item) {
                $dataItem = explode('-', $item->external_data);
                $comment = '';
                $discount = 0;
                $subTotal = $dataItem[7] / 100;
                $jsonDiscount = null;

                if (isset($item->customer_request->special_instructions)) {
                    $comment = $item->customer_request->special_instructions;
                }

                if ($useUberDiscountCalc) {
                    if (isset($priceBreakdownMap[$item->cart_item_id])) {
                        $pb = $priceBreakdownMap[$item->cart_item_id];
                        $totalDeseado = $pb['discount_gross'] / $pb['quantity'];
                        $ivaRate = $dataItem[5] / 100;
                        $discount = $subTotal - ($totalDeseado / (1 + $ivaRate));
                        $discount = max(0, min($discount, $subTotal));

                        if ($discount > 0) {
                            $jsonDiscount = json_encode([
                                'id_descuento' => '-1',
                                'nombre' => 'PROMO_UBER',
                                'tipo' => 'MONTO',
                                'aplicacion' => 'ITEM',
                                'monto' => number_format($discount, 4, '.', ''),
                                'porcentaje' => null,
                                'condicion_aplicable' => 0,
                                'producto' => $dataItem[0] . '_' . $dataItem[1]
                            ]);
                        }
                    }
                } else {
                    if (count($promoItem)) {
                        $indexPromo = array_search($item->id, array_column($promoItem, 'external_id'));

                        if ($indexPromo !== false && isset($promoItem[$indexPromo]) && $promoItem[$indexPromo]['discounted_quantity'] > 0) {
                            $discount = $promoItem[$indexPromo]['discount_amount_applied'];

                            $jsonDiscount = json_encode([
                                'id_descuento' => '-1',
                                'nombre' => $promoItem[$indexPromo]['external_promotion_id'],
                                'tipo' => 'MONTO',
                                'aplicacion' => 'ITEM',
                                'monto' => $discount,
                                'porcentaje' => null,
                                'condicion_aplicable' => 0,
                                'producto' => $dataItem[0] . '_' . $dataItem[1]
                            ]);

                            if ($discount > $subTotal) $discount = $subTotal;

                            $promoItem[$indexPromo]['discounted_quantity'] -= 1;
                        }
                    }
                }

                $items[] = [
                    'type' => $dataItem[0],
                    'id' => $dataItem[1],
                    'name' => $item->title,
                    'tax' => $dataItem[5],
                    'quantity' => $item->quantity->amount,
                    'ingredient' => 0,
                    'comment' => $comment,
                    'sub_total_price' => $subTotal,
                    'id_pcpp' => null,
                    'discount' => $discount,
                    'json_discount' => $jsonDiscount,
                ];

                if (isset($item->selected_modifier_groups)) {
                    foreach ($item->selected_modifier_groups as $question) {
                        $idPcpp = explode('-', $question->external_data)[3];

                        if (isset($question->selected_items)) {
                            foreach ($question->selected_items as $res) {
                                $dataResponse = explode('-', $res->external_data);
                                $discount = 0;
                                $subTotal = $dataResponse[9] / 100;
                                $jsonDiscount = null;

                                if ($useUberDiscountCalc) {
                                    if (isset($priceBreakdownMap[$res->cart_item_id])) {
                                        $pb = $priceBreakdownMap[$res->cart_item_id];
                                        $totalDeseado = $pb['discount_gross'] / $pb['quantity'];
                                        $ivaRate = $dataResponse[7] / 100;
                                        $discount = $subTotal - ($totalDeseado / (1 + $ivaRate));
                                        $discount = max(0, min($discount, $subTotal));

                                        if ($discount > 0) {
                                            $jsonDiscount = json_encode([
                                                'id_descuento' => '-1',
                                                'nombre' => 'PROMO_UBER',
                                                'tipo' => 'MONTO',
                                                'aplicacion' => 'ITEM',
                                                'monto' => number_format($discount, 4, '.', ''),
                                                'porcentaje' => null,
                                                'condicion_aplicable' => 0,
                                                'producto' => $dataResponse[0] . '_' . $dataResponse[1]
                                            ]);
                                        }
                                    }
                                } else {
                                    if (isset($order->payment->tax_reporting->breakdown->promotions)) {
                                        $arrItemsPromo = array_filter($order->payment->tax_reporting->breakdown->promotions, function ($itemPromo) use ($res) {
                                            return $res->cart_item_id === $itemPromo->instance_id && $itemPromo->description === 'ITEM_PROMOTION';
                                        });

                                        foreach ($arrItemsPromo as $itemPromo) {
                                            $discount = number_format(($itemPromo->net_amount->amount_e5 / 100000) * -1, 2, '.', '');

                                            $jsonDiscount = json_encode([
                                                'id_descuento' => '-1',
                                                'nombre' => 'PROMO_UBER',
                                                'tipo' => 'MONTO',
                                                'aplicacion' => 'ITEM',
                                                'monto' => $discount,
                                                'porcentaje' => null,
                                                'condicion_aplicable' => 0,
                                                'producto' => $dataResponse[0] . '_' . $dataResponse[1]
                                            ]);
                                        }

                                        if ($discount > $subTotal)
                                            $discount = $subTotal;
                                    }
                                }

                                $items[] = [
                                    'type' => $dataResponse[0],
                                    'id' => $dataResponse[1],
                                    'name' => $res->title,
                                    'ingredient' => 1,
                                    'tax' => $dataResponse[7],
                                    'quantity' => $res->quantity->amount * $item->quantity->amount,
                                    'id_pcpp' => $idPcpp,
                                    'sub_total_price' => $subTotal,
                                    'discount' => $discount,
                                    'comment' => '',
                                    'json_discount' => $jsonDiscount,
                                ];
                            }
                        }
                    }
                }
            }
        }

        $this->renderPosTable($items, $promoSubTotal, $discountType);
    }

    private function renderPosTable($items, $promoSubTotal, $discountType)
    {
        $useUberDiscountCalc = ($discountType === 'UBER_CREATED');

        $this->info('DETALLE');
        $this->newLine();

        $headers = ['Prod.', 'Cant.', 'Unit.', 'Desc.', 'IVA', 'Total'];
        $rows = [];

        $base0 = 0;
        $base15 = 0;
        $descuento1 = 0;
        $descuento2 = 0;

        foreach ($items as $item) {
            $unit = $item['sub_total_price'];
            $discount = $item['discount'];
            $qty = $item['quantity'];
            $tax = $item['tax'];

            if ($useUberDiscountCalc) {
                $totalDiscount = $discount * $qty;
                $netUnit = $unit - $discount;
                $ivaAmount = $netUnit * ($tax / 100) * $qty;
                $totalNet = $netUnit * (1 + $tax / 100) * $qty;
            } else {
                $totalDiscount = $discount;
                $netSubtotal = ($unit * $qty) - $discount;
                $ivaAmount = $netSubtotal * ($tax / 100);
                $totalNet = $netSubtotal * (1 + $tax / 100);
            }

            $rows[] = [
                $item['name'],
                $qty,
                '$' . number_format($unit, 2),
                '$' . number_format($totalDiscount, 2),
                '$' . number_format($ivaAmount, 2),
                '$' . number_format($totalNet, 2),
            ];

            $baseForTax = $useUberDiscountCalc ? ($unit - $discount) * $qty : ($unit * $qty) - $discount;

            if ($tax == 0) {
                $base0 += $baseForTax;
            } else {
                $base15 += $baseForTax;
            }

            $descuento1 += $totalDiscount;
        }

        if (count($promoSubTotal)) {
            $descuento2 = array_sum(array_column($promoSubTotal, 'discount_value'));
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info('------------------------------------------');

        $iva15 = $base15 * 0.15;
        $subTotal15 = $base15;
        $total = $base0 + $base15 + $iva15 - $descuento2;

        $this->line(sprintf('  Base 0%%:                    $%s', number_format($base0, 2)));
        $this->line(sprintf('  Base 15%%:                   $%s', number_format($base15, 2)));
        $this->line(sprintf('  Descuento1:                  $%s', number_format($descuento1, 2)));
        $this->line(sprintf('  Descuento2:                  $%s', number_format($descuento2, 2)));
        $this->line(sprintf('  Sub total 0%%:                 $%s', number_format($base0, 2)));
        $this->line(sprintf('  Sub total 15%%:                $%s', number_format($subTotal15, 2)));
        $this->line(sprintf('  Servicio:                    $%s', number_format(0, 2)));
        $this->line(sprintf('  Iva 0:                       $%s', number_format(0, 2)));
        $this->line(sprintf('  Iva 15%%:                      $%s', number_format($iva15, 2)));
        $this->line(sprintf('  Total:                       $%s', number_format($total, 2)));
        $this->line(sprintf('  Propina:                     $%s', number_format(0, 2)));
        $this->line(sprintf('  Total con propina:           $%s', number_format($total, 2)));
        $this->info('------------------------------------------');
    }
}
