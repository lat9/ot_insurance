<?php
/**
 * Order Total Module
 *
 * @package - Optional Insurance
 * @copyright Copyright 2007-2008 Numinix Technology http://www.numinix.com
 * @copyright Copyright 2003-2007 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: ot_insurance.php 2018-01-19 Lynda Leung $
 */
class ot_insurance
{
    public string $title;
    public array $output;
    public string $code;
    public string $description;
    public bool $enabled;
    public null|int $sort_order;
    public int $tax_class;
    public bool $credit_class;
    public array $eoInfo = [];

    protected int $check;
    protected int $num_zones;
    protected int $dest_zone;

    protected bool $noInsuranceOnFreeShippingProducts;
    protected bool $noInsuranceOnVirtualOrders;
    protected bool $noInsuranceOnGvOnlyOrders;

    protected int|float $insuranceFee;
    protected int|float $insuranceIncrement;
    protected int|float $insuranceRequiredOver;
    protected int|float $insuranceValueExempt;
    protected int|float $insurancePercentage;

    protected string $calculationType;

    protected int|float $insurableTotal = 0;
    protected int|float $insurance = 0;

    public function __construct()
    {
        $this->code = 'ot_insurance';
        $this->title = MODULE_ORDER_TOTAL_INSURANCE_TITLE;
        $this->description = MODULE_ORDER_TOTAL_INSURANCE_DESCRIPTION;

        global $db;
        $geozones = $db->Execute("SELECT * FROM " . TABLE_GEO_ZONES);
        $this->num_zones = $geozones->RecordCount();

        $this->sort_order = defined('MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER') ? (int)MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER : null;
        if ($this->sort_order === null) {
            return;
        }

        $this->enabled = (MODULE_ORDER_TOTAL_INSURANCE_STATUS === 'true');
        $this->credit_class = true;

        $this->eoInfo = [
            'installed' => false,
            'value' => 0,
        ];

        $this->output = [];
        $this->tax_class = (int)MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS;

        global $order;

        if ($this->enabled === false || !isset($order)) {
            return;
        }

        $this->initialize();
     }

    // -----
    // Convert a string value to either an int or float, depending on
    // the presence of a '.' in the value.
    //
    protected function convertToIntOrFloat(string $value): int|float
    {
        if (strpos($value, '.') === false) {
            return (int)$value;
        }
        return (float)$value;
    }

    public function process()
    {
        if ($this->enabled === false) {
            return;
        }

        $this->initialize();
        if ($this->enabled === false) {
            return;
        }

        $this->initializeSelection();
        $this->calculateInsurance();
        if ($this->insurance <= 0 || (empty($_SESSION['opt_insurance']) && $this->insurableTotal <= $this->insuranceRequiredOver)) {
            return;
        }

        global $order, $currencies;

        $tax = 0;
        if ($this->tax_class > 0) {
            $module = $order->info['shipping_module_code'];
            $shipping_tax_basis = $GLOBALS[$module]->tax_basis ?? STORE_SHIPPING_TAX_BASIS;

            if ($shipping_tax_basis === 'Billing' || $order->content_type === 'virtual') {
                $country_id = $order->billing['country']['id'];
                $zone_id = $order->billing['zone_id'];
            } elseif ($shipping_tax_basis === 'Shipping') {
                $country_id = $order->delivery['country']['id'];
                $zone_id = $order->delivery['zone_id'];
            } else {
                $country_id = STORE_COUNTRY;
                $zone_id = STORE_ZONE;
            }
            $tax_rate = zen_get_tax_rate($this->tax_class, $country_id, $zone_id);
            $tax = zen_calculate_tax($this->insurance, $tax_rate);
            $tax_description = zen_get_tax_description($this->tax_class, $country_id, $zone_id);

            $order->info['tax_groups'][$tax_description] += $tax;
            $order->info['tax'] += $tax;
        }
        $order->info['total'] += $this->insurance + $tax;

        $this->output[] = [
            'title' => $this->title . ':',
            'text' => $currencies->format($this->insurance, true, $order->info['currency'], $order->info['currency_value']),
            'value' => $this->insurance,
        ]; 
    }

    public function pre_confirmation_check($order_total)
    {
    }

    public function credit_selection(): array
    {
        global $order, $currencies;

        if ($this->enabled === false) {
            return [];
        }

        $this->initialize();
        if ($this->enabled === false) {
            return [];
        }

        $this->initializeSelection();
        $this->calculateInsurance();
        if ($this->insurance <= 0 || $this->insurableTotal > $this->insuranceRequiredOver) {
            return [];
        }

        $insurance_array = [
            [
                'id' => '0',
                'text' => MODULE_ORDER_TOTAL_INSURANCE_ADD_NO
            ],
            [
                'id' => '1',
                'text' => MODULE_ORDER_TOTAL_INSURANCE_ADD_YES
            ]
        ];
        $selected = !empty($_SESSION['opt_insurance']) ? '1' : '0';
        $selection = [
            'id' => $this->code,
            'module' => $this->title,
            'redeem_instructions' => MODULE_ORDER_TOTAL_INSURANCE_TEXT_ENTER_CODE,
            'fields' => [
                [
                    'tag' => 'opt-insurance',
                    'field' => zen_draw_pull_down_menu('opt_insurance', $insurance_array, $selected, 'id="opt-insurance"'),
                    'title' => sprintf(MODULE_ORDER_TOTAL_INSURANCE_ADD, $currencies->format($this->insurance, true, $order->info['currency'], $order->info['currency_value'])),
                ],
            ],
        ];
        return $selection;
    }

    protected function initialize(): void
    {
        global $order;
        if (str_starts_with($order->info['shipping_module_code'], 'storepickup_')) {
            $this->enabled = false;
            return;
        }

        // -----
        // Initialize protected properties that reflect the various limits on
        // what to "not" include when determining whether/not to display/include
        // the order-total.
        //
        $this->noInsuranceOnFreeShippingProducts = (MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING === 'true');
        $this->noInsuranceOnVirtualOrders = (MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL === 'true');
        $this->noInsuranceOnGvOnlyOrders = (MODULE_ORDER_TOTAL_INSURANCE_GV === 'true');

        // -----
        // Convert various numeric values into their integer/float version for use in calculations.
        //
        $this->insuranceIncrement = $this->convertToIntOrFloat(MODULE_ORDER_TOTAL_INSURANCE_INCREMENT);
        if ($this->insuranceIncrement == 0) {
            $this->insuranceIncrement = 100;
        }
        $this->insuranceFee = $this->convertToIntOrFloat(MODULE_ORDER_TOTAL_INSURANCE_FEE);
        $this->insuranceRequiredOver = $this->convertToIntOrFloat(MODULE_ORDER_TOTAL_INSURANCE_REQUIRED);
        $this->insuranceValueExempt = $this->convertToIntOrFloat(MODULE_ORDER_TOTAL_INSURANCE_OVER);
        $this->insurancePercentage = $this->convertToIntOrFloat(MODULE_ORDER_TOTAL_INSURANCE_PER) / 100;

        $this->calculationType = MODULE_ORDER_TOTAL_INSURANCE_TYPE;
        if (MODULE_ORDER_TOTAL_INSURANCE_TABLE === 'true') {
            $this->calculationType = 'table';
        }

        // -----
        // If the order's insurable total is over the amount where insurance is required,
        // reset this module's credit_class indication since the insurance is no
        // longer opt-in.
        //
        $this->insurableTotal = $_SESSION['cart']->show_total();
        if ($this->noInsuranceOnFreeShippingProducts === true) {
            $this->insurableTotal -= $_SESSION['cart']->free_shipping_prices();
        }

        global $order, $currencies;

        if ($order->content_type === 'virtual') {
            if ($this->noInsuranceOnVirtualOrders === true) {
                $this->insurableTotal = 0;
            } elseif ($this->noInsuranceOnGvOnlyOrders === true) {
                $cart_gv_price = $_SESSION['cart']->gv_only();
                if ($cart_gv_price > 0 && $cart_gv_price == $this->insurableTotal) {
                    $this->insurableTotal = 0;
                }
            }
        }
        if ($this->insurableTotal > $this->insuranceRequiredOver) {
            $this->credit_class = false;
        }

        // -----
        // Check the insurance zones and associated cost **only if** using
        // the table-rates' lookup for insurance calculations.
        //
        if ($this->calculationType !== 'table') {
            return;
        }

        global $order;
        $this->dest_zone = 0;
        $delivery_country_id = (int)($order->delivery['country']['id'] ?? 0);
        $delivery_zone_id = ($order->delivery['zone_id'] ?? -1);
        for ($i = 1; $i <= $this->num_zones; $i++) {
            $insurance_zone = "MODULE_ORDER_TOTAL_INSURANCE_ZONE_$i";
            if (defined($insurance_zone) && (int)constant($insurance_zone) > 0) {
                $check = $db->Execute(
                    "SELECT zone_id
                       FROM " . TABLE_ZONES_TO_GEO_ZONES . "
                      WHERE geo_zone_id = " . (int)constant($insurance_zone) . "
                        AND zone_country_id = $delivery_country_id
                      ORDER BY zone_id"
                );
                foreach ($check as $next_zone) {
                    if ($next_zone['zone_id'] < 1 || $next_zone['zone_id'] == $delivery_zone_id) {
                        $this->dest_zone = $i;
                        break;
                    }
                }
            }
        }

        if ($this->dest_zone < 1 && defined('MODULE_ORDER_TOTAL_INSURANCE_ZONE_1') && MODULE_ORDER_TOTAL_INSURANCE_ZONE_1 > 0) {
            $this->enabled = false;
        }

        if (!defined('MODULE_ORDER_TOTAL_INSURANCE_ZONE_' . $this->dest_zone) || !defined('MODULE_ORDER_TOTAL_INSURANCE_COST_' . $this->dest_zone)) {
            $this->enabled = false;
        }
    }

    // -----
    // This method provides integration with EO 5.0.0 and later. That version of EO maintains
    // a list of credit-class order-total modules that are currently used in the order.
    //
    protected function initializeSelection(): void
    {
        if (isset($_POST['opt_insurance'])) {
            $_SESSION['opt_insurance'] = !empty($_POST['opt_insurance']);
        } else {
            $_SESSION['opt_insurance'] ??= $this->eoInfo['installed'];
        }
    }

    protected function calculateInsurance(): void
    {
        if ($this->enabled === false) {
            return;
        }

        $this->insurableTotal = $_SESSION['cart']->show_total();
        if ($this->noInsuranceOnFreeShippingProducts === true) {
            $this->insurableTotal -= $_SESSION['cart']->free_shipping_prices();
        }

        global $order, $currencies;

        if ($order->content_type === 'virtual') {
            if ($this->noInsuranceOnVirtualOrders === true) {
                return;
            }

            if ($this->noInsuranceOnGvOnlyOrders === true) {
                $cart_gv_price = $_SESSION['cart']->gv_only();
                if ($cart_gv_price > 0 && $cart_gv_price == $this->insurableTotal) {
                    $this->insurableTotal = 0;
                    return;
                }
            }
        }

        if ($this->calculationType === 'percent') {
            $this->insurance = ($order->info['subtotal'] * $this->insurancePercentage);

        } elseif ($this->calculationType === 'amount') {
            $insurance_less_exempt = $this->insurableTotal - $this->insuranceValueExempt;
            if ($insurance_less_exempt < 0) {
                $insurance_less_exempt = 0;
            }
            $number_of_increments = ceil($insurance_less_exempt / $this->insuranceIncrement);
            $this->insurance = $this->insuranceFee * $number_of_increments;

        } else {
            $table = constant('MODULE_ORDER_TOTAL_INSURANCE_COST_' . $this->dest_zone);
            $table_cost = preg_split('/[:,]/', $table);
            for ($i = 0, $size = count($table_cost); $i < $size; $i += 2) {
                if (round($this->insurableTotal, 9) <= $table_cost[$i]) {
                    $this->insurance = $table_cost[$i + 1];
                    break;
                }
            }

            // if the measured amount is greater than the table, use maximal cost
            if (round($order_total_insurance, 9) >= $table_cost[$size - 2]) {
                $number_of_increments = ceil(($this->insurableTotal - $table_cost[$size - 2]) / $this->insuranceIncrement);
                $total_increments = $number_of_increments * $this->insuranceFee;
                $this->insurance = $table_cost[$size - 1] + $total_increments;
            }
        }
    }

    public function update_credit_account($i)
    {
    }

    public function apply_credit()
    {
    }

    public function clear_posts()
    {
        unset($_SESSION['opt_insurance']);
    }

    public function collect_posts()
    {
        $this->initializeSelection();
    }

    public function check()
    {
        global $db;
        if (!isset($this->check)) {
            $check_query = $db->Execute(
                "SELECT configuration_value
                   FROM " . TABLE_CONFIGURATION . "
                  WHERE configuration_key = 'MODULE_ORDER_TOTAL_INSURANCE_STATUS'
                  LIMIT 1"
            );
            $this->check = (int)$check_query->RecordCount();
        }
        return $this->check;
    }

    public function keys(): array
    {
        $keys = [
            'MODULE_ORDER_TOTAL_INSURANCE_STATUS',
            'MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER',
            'MODULE_ORDER_TOTAL_INSURANCE_TABLE',
            'MODULE_ORDER_TOTAL_INSURANCE_TYPE',
            'MODULE_ORDER_TOTAL_INSURANCE_PER',
            'MODULE_ORDER_TOTAL_INSURANCE_FEE',
            'MODULE_ORDER_TOTAL_INSURANCE_INCREMENT',
            'MODULE_ORDER_TOTAL_INSURANCE_OVER',
            'MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS',
            'MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL',
            'MODULE_ORDER_TOTAL_INSURANCE_GV',
            'MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING',
            'MODULE_ORDER_TOTAL_INSURANCE_REQUIRED',
        ];

        for ($i = 1; $i <= $this->num_zones; $i++) {
            $keys[] = 'MODULE_ORDER_TOTAL_INSURANCE_ZONE_' . $i;
            $keys[] = 'MODULE_ORDER_TOTAL_INSURANCE_COST_' . $i;
        }
        return $keys;
    }

    public function install(): void
    {
        global $db;

        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . "
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)
             VALUES
                ('Enable Insurance Module', 'MODULE_ORDER_TOTAL_INSURANCE_STATUS', 'true', 'Do you want to enable this module? To fully turn this off, both this option and the one below should be set to false.', 6, 1, NULL, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Sort Order', 'MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER', '299', 'Sort order of display. Note: Must be higher than the sub-total.', 6, 3, NULL, NULL, now()),

                ('Use Table Rates?', 'MODULE_ORDER_TOTAL_INSURANCE_TABLE', 'true', 'Do you want to use Table Rates?', 6, 4, NULL, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Alternate Insurance Type', 'MODULE_ORDER_TOTAL_INSURANCE_TYPE', 'percent', 'If not using <b>Table Rates</b>, would you like to charge by percentage of the cart sub-total or by a specific amount?', 6, 5, NULL, 'zen_cfg_select_option([\'percent\', \'amount\'], ', now()),

                ('Insurance Percentage', 'MODULE_ORDER_TOTAL_INSURANCE_PER', '5', 'Used with <code>percent</code> calculations. What percentage should be applied to the cart sub-total to calculate the insurance amount?', 6, 6, NULL, NULL, now()),

                ('Insurance Rate', 'MODULE_ORDER_TOTAL_INSURANCE_FEE', '.50', 'Used with <code>amount</code> and <b>Table Rates</b> calculations. What amount do you want to charge per <b>Increment Amount</b>?', 6, 7, 'currencies->format', NULL, now()),

                ('Increment Amount', 'MODULE_ORDER_TOTAL_INSURANCE_INCREMENT', '100', 'Used with <code>amount</code> and <b>Table Rates</b> calculations. Identify the amount-increment for which the <b>Insurance Rate</b> is applied. If, for example, the increment-amount is <var>100</var> and the rate is <var>.50</var>, the insurance is calculated as \$0.50 for each \$100.00 of the total.', 6, 8, 'currencies->format', NULL, now()),

                ('Amount Exempt From Fee', 'MODULE_ORDER_TOTAL_INSURANCE_OVER', '100', 'Used with <code>amount</code> calculations. Set this to the amount of the total that is exempt from the insurance calculations, i.e. set to 100 for all orders under 100 to be exempt, already insured, etc.', 6, 9, 'currencies->format', NULL, now()),

                ('Tax Class', 'MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS', '0', 'Use the following tax class on the insurance fee.', 6, 10, 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now()),

                ('No Insurance Fee on Virtual Products', 'MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL', 'true', 'Do not charge insurance fee when cart is Virtual Products Only', 6, 11, NULL, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('No Insurance Fee on Gift Vouchers', 'MODULE_ORDER_TOTAL_INSURANCE_GV', 'true', 'Do not charge insurance fee when cart is Gift Vouchers only', 6, 12, NULL, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('No Insurance Fee on Free Shipping', 'MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING', 'true', 'Do not calculate insurance for products that have free shipping (includes gv and virtual products)', 6, 12, NULL, 'zen_cfg_select_option([\'true\', \'false\'], ', now()),

                ('Required Insurance Amount', 'MODULE_ORDER_TOTAL_INSURANCE_REQUIRED', '100', 'Automatically charge shipping insurance for amounts over X dollars', 6, 14, 'currencies->format', NULL, now())"
        );

        for ($i = 1; $i <= $this->num_zones; $i++) {
            if ($i === 1) {
                $db->Execute(
                    "INSERT INTO " . TABLE_CONFIGURATION . "
                        (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)
                     VALUES
                        ('Insurance Zone " . $i . "', 'MODULE_ORDER_TOTAL_INSURANCE_ZONE_" . $i . "', '0', 'If a zone is selected, only enable this insurance for that zone (Note: use this field for non-table rates as well).', 6, 0, 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())"
                );
            } else {
                $db->Execute(
                    "INSERT INTO " . TABLE_CONFIGURATION . "
                        (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)
                     VALUES 
                        ('Insurance Zone " . $i . "', 'MODULE_ORDER_TOTAL_INSURANCE_ZONE_" . $i . "', '0', 'If a zone is selected, only enable this insurance for that zone.', 6, 0, 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())"
                );
            }
            $db->Execute(
                "INSERT INTO " . TABLE_CONFIGURATION . "
                    (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
                 VALUES
                    ('Zone " . $i ." Insurance Table', 'MODULE_ORDER_TOTAL_INSURANCE_COST_" . $i ."', '50:1.70,100:2.15,200:2.60,300:4.60,400:5.55,500:6.50,600:7.45', 'The insurance cost is based on the total cost of the items. Example: 25:8.50,50:5.50,etc.. Up to 25 charge 8.50, from there to 50 charge 5.50, etc', '6', '0', 'zen_cfg_textarea(', now())"
            );
        }
    }

    public function remove()
    {
        global $db;
        $db->Execute(
            "DELETE FROM " . TABLE_CONFIGURATION . "
              WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')
                 OR configuration_key LIKE 'MODULE\_ORDER\_TOTAL\_INSURANCE\_ZONE\_%'
                 OR configuration_key LIKE 'MODULE\_ORDER\_TOTAL\_INSURANCE\_COST\_%'"
        );
    }
}
