<?php
/**
 * Order Total Module
 *
 *
 * @package - Optional Insurance
 * @copyright Copyright 2007-2008 Numinix Technology http://www.numinix.com
 * @copyright Copyright 2003-2007 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: ot_insurance.php 2018-01-19 Lynda Leung $
 */


if ($_SESSION['shipping']['id'] == 'storepickup_storepickup') {
class ot_insurance extends base{
    function process() {
		}
	}
} else {

	class ot_insurance {

    var $title, $output, $num_zones, $enabled, $dest_zone;
    
    function ot_insurance()
    {
	  	global $order, $currencies, $db;
	  	
      $this->code = 'ot_insurance';
      $this->title = MODULE_ORDER_TOTAL_INSURANCE_TITLE;
      $this->description = MODULE_ORDER_TOTAL_INSURANCE_DESCRIPTION;
      $this->enabled = ((MODULE_ORDER_TOTAL_INSURANCE_STATUS == 'true') ? true : false);
      $this->sort_order = MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER;
      $this->credit_class = true;
      $this->output = array();
      $geozones = $db->Execute("SELECT * FROM " . TABLE_GEO_ZONES);
      $this->num_zones = $geozones->RecordCount();
     	
     	if ($this->enabled == true) {
	     	$this->dest_zone = 0;
				for ($i=1; $i<=$this->num_zones; $i++) {
					if ((int)constant('MODULE_ORDER_TOTAL_INSURANCE_ZONE_' . $i) > 0) {
						$check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . constant('MODULE_ORDER_TOTAL_INSURANCE_ZONE_' . $i) . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
						while (!$check->EOF) {
							if ($check->fields['zone_id'] < 1) {
								$this->dest_zone = $i;
								break;
							} elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
								$this->dest_zone = $i;
								break;
							}
							$check->MoveNext();
						} // end while
					} // END if ((int)constant('MODULE_ORDER_TOTAL_INSURANCE_ZONE_' . $i) > 0)
				} // END for ($i=1; $i<=$this->num_zones; $i++)
				if ($this->dest_zone < 1 && MODULE_ORDER_TOTAL_INSURANCE_ZONE_1 > 0) {
				  $this->enabled = false;
				}
			}
    }
    
    
    function process() {
      global $order, $currencies, $db;
      
      if ($this->enabled) {
	    	$order_total = $this->get_order_total();
	    	$order_total_insurance = $_SESSION['cart']->show_total();	    
	    	if (MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING == 'true') {
		    	$order_total_insurance = $order_total_insurance - $_SESSION['cart']->free_shipping_prices();
	    	}
        
        if ($order_total_insurance <= MODULE_ORDER_TOTAL_INSURANCE_OVER) return false;
        
        if (MODULE_ORDER_TOTAL_INSURANCE_TYPE =='percent') {
          $type = true;
        } else {
          $type = false;
        }
        
        if (MODULE_ORDER_TOTAL_INSURANCE_TABLE == 'true') {
          $usps = true;
        } else {
          $usps = false;
        }
        

        $cart_content_type = $_SESSION['cart']->get_content_type();
        $gv_content_only = $_SESSION['cart']->gv_only();
        if ($cart_content_type == 'physical' or $cart_content_type == 'mixed') {
          $charge_it = true;
        } else {
          // check to see if everything is virtual, if so - skip the low order fee.
          if ((($cart_content_type == 'virtual') and MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL == 'true')) {
            $charge_it = false;
            if ((($gv_content_only > 0) and MODULE_ORDER_TOTAL_INSURANCE_GV == 'false')) {
              $charge_it = true;
            }
          }
            
          if ((($gv_content_only > 0) and MODULE_ORDER_TOTAL_INSURANCE_GV == 'true')) {
            // check to see if everything is gift voucher, if so - skip the low order fee.
            $charge_it = false;
            if ((($cart_content_type == 'virtual') and MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL == 'false')) {
              $charge_it = true;
            }
          }
        }
        //end else

          
        if (!$_SESSION['insurance']) {
          $charge_it = 'false';
        }
        if ($_SESSION['opt_insurance']) {
          $charge_it = 'true';
        }
        if ($charge_it && ($order_total_insurance > MODULE_ORDER_TOTAL_INSURANCE_REQUIRED)) {
          $charge_it = 'true';
        }
          
        if ($charge_it == 'true') {
          if ($usps == 'true') {
            // usps still open
			      $table = constant('MODULE_ORDER_TOTAL_INSURANCE_COST_' . $this->dest_zone);
				    $table_cost = preg_split("/[:,]/", $table);
						$size = sizeof($table_cost);
						for ($i=0, $n=$size; $i<$n; $i+=2) {
							if (round($order_total_insurance,9) <= $table_cost[$i]) {
								$insurance = $table_cost[$i+1];
								break;
							}
						} 
            // if the measured amount is greater than the table, use maximal cost
            if (round($order_total_insurance,9) >= $table_cost[$size-2]) {
              $number_of_increments = ceil(($order_total_insurance - $table_cost[$size-2]) / MODULE_ORDER_TOTAL_INSURANCE_INCREMENT); 
              $total_increments = $number_of_increments * MODULE_ORDER_TOTAL_INSURANCE_FEE;
              $insurance = $table_cost[$size-1] + $total_increments;
            }
                           
          } else {
            if ($type == 'true') {	
							$insurance = ($order->info['subtotal'] * MODULE_ORDER_TOTAL_INSURANCE_PER / 100);
            } else {                   
              $how_often = ceil(($order->info['total']- $order->info['tax'] - MODULE_ORDER_TOTAL_INSURANCE_OVER)/MODULE_ORDER_TOTAL_INSURANCE_INCREMENT);
              $insurance = MODULE_ORDER_TOTAL_INSURANCE_FEE * $how_often;
            }
          } // end type             
      	} elseif ($charge_it == 'false') {
          $insurance = 0;
          return false;
      	}// end charge_it
        if (!$shipping_id) {
          $shipping_id = $_SESSION['shipping']['id'];
          $module = substr($shipping_id, 0, strpos($shipping_id, '_'));
        }
        if (MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS > 0) {
          if (!defined($GLOBALS[$module]->tax_basis)) {
            $shipping_tax_basis = STORE_SHIPPING_TAX_BASIS;
          } else {
            $shipping_tax_basis = $GLOBALS[$module]->tax_basis;
          }
            
          if ($shipping_tax_basis == 'Billing') {
            $tax = zen_get_tax_rate(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->billing['country']['id'], $order->billing['zone_id']);
            $tax_description = zen_get_tax_description(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->billing['country']['id'], $order->billing['zone_id']);
          } elseif ($shipping_tax_basis == 'Shipping') {
            $tax = zen_get_tax_rate(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->delivery['country']['id'], $order->delivery['zone_id']);
            $tax_description = zen_get_tax_description(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->delivery['country']['id'], $order->delivery['zone_id']);
          } else {
            if (STORE_ZONE == $order->billing['zone_id']) {
              $tax = zen_get_tax_rate(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->billing['country']['id'], $order->billing['zone_id']);
              $tax_description = zen_get_tax_description(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->billing['country']['id'], $order->billing['zone_id']);
            } elseif (STORE_ZONE == $order->delivery['zone_id']) {
              $tax = zen_get_tax_rate(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->delivery['country']['id'], $order->delivery['zone_id']);
              $tax_description = zen_get_tax_description(MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS, $order->delivery['country']['id'], $order->delivery['zone_id']);
            } else {
              $tax = 0;
            }
          }
        }
        $order->info['tax_groups']["$tax_description"] += zen_calculate_tax($insurance, $tax);
        $order->info['tax'] += zen_calculate_tax($insurance, $tax);
        $order->info['total'] += $insurance + zen_calculate_tax($insurance, $tax);
        $this->output[] = array('title' => $this->title . ':',
        'text' => $currencies->format($insurance, true, $order->info['currency'], $order->info['currency_value']),
        'value' => $insurance); 
			}
		}

	  function get_order_total() {
      global $order;
	    $order_total_tax = $order->info['tax'];
	    $order_total = $order->info['total'];
	    if ($this->include_shipping != 'true') $order_total -= $order->info['shipping_cost'];
	    if ($this->include_tax != 'true') $order_total -= $order->info['tax'];
	    $orderTotalFull = $order_total;
	    $order_total = array('totalFull'=>$orderTotalFull, 'total'=>$order_total, 'tax'=>$order_total_tax);
	
	    return $order_total;
  	}
	
	  function pre_confirmation_check($order_total) {
    }
    
    function credit_selection() {
	    global $order, $currencies, $db;
	    
	    if ($this->enabled) {
		    $order_total = $this->get_order_total();
				$order_total_insurance = $_SESSION['cart']->show_total();
				if (MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING == 'true') {
		    	$order_total_insurance = $order_total_insurance - $_SESSION['cart']->free_shipping_prices();
	    	}
        
        if ($order_total_insurance <= MODULE_ORDER_TOTAL_INSURANCE_OVER) return false;
				
		    if (MODULE_ORDER_TOTAL_INSURANCE_TABLE == 'true') {
		      $table = constant('MODULE_ORDER_TOTAL_INSURANCE_COST_' . $this->dest_zone);
			    $table_cost = preg_split("/[:,]/", $table);
					$size = sizeof($table_cost);
					for ($i=0, $n=$size; $i<$n; $i+=2) {
						if (round($order_total_insurance,9) <= $table_cost[$i]) {
							$insurance = $table_cost[$i+1];
							break;
							}
					}
				// if the measured amount is greater than the table, use maximal cost
				if (round($order_total_insurance,9) >= $table_cost[$size-2]) {
					$number_of_increments = ceil(($order_total_insurance - $table_cost[$size-2]) / MODULE_ORDER_TOTAL_INSURANCE_INCREMENT); 
					$total_increments = $number_of_increments * MODULE_ORDER_TOTAL_INSURANCE_FEE;
					$insurance = $table_cost[$size-1] + $total_increments;
				}
		    } else {
		      if (MODULE_ORDER_TOTAL_INSURANCE_TYPE =='percent') {
		        $insurance = ($order->info['subtotal'] * MODULE_ORDER_TOTAL_INSURANCE_PER / 100);
		      } else {
		        $how_often = ceil(($order->info['total']- $order->info['tax'] - MODULE_ORDER_TOTAL_INSURANCE_OVER)/MODULE_ORDER_TOTAL_INSURANCE_INCREMENT);
		        $insurance = MODULE_ORDER_TOTAL_INSURANCE_FEE * $how_often;
		      }
		    }
	       
	      $selected = (($_SESSION['opt_insurance'] == '1') ? true : false);
	      $display_insurance = true;
	      if ( ($order_total_insurance >= MODULE_ORDER_TOTAL_INSURANCE_REQUIRED) || ( ($order_total_insurance <= MODULE_ORDER_TOTAL_INSURANCE_OVER) && (MODULE_ORDER_TOTAL_INSURANCE_OVER > 0) && ($order_total_insurance >= MODULE_ORDER_TOTAL_INSURANCE_REQUIRED) ) ) {
		      $display_insurance = false;
	      }
	      if ($display_insurance) {
			    $selection = array('id' => $this->code,
			    'module' => $this->title,
			    'redeem_instructions' => MODULE_ORDER_TOTAL_INSURANCE_TEXT_ENTER_CODE,
			    'fields' => array(array('field' => zen_draw_checkbox_field('opt_insurance', '1', $selected, 'id="opt_insurance"' . ((FILENAME_CHECKOUT_SHIPPING == 'checkout' || FEC_STATUS == 'true') ? ' onclick="updateForm();"' : '')) . zen_draw_hidden_field('insurance', '1'),
			        										'title' => $currencies->format($insurance, true, $order->info['currency'], $order->info['currency_value'])
			    )));
	      } else { 
		      $selection = false;
	      }
	      return $selection;
      }
    }
    

	
    function update_credit_account($i)
    {
    }
	
    function apply_credit()
    {
    }
    
    function clear_posts()
    {
        unset($_SESSION['insurance']);
    }
    
    function collect_posts()
    {
        global $db, $currencies;
        if ($_POST['opt_insurance']) {
          $_SESSION['insurance'] = $_POST['opt_insurance'];
        } elseif (isset($_POST['insurance'])) {  // check to make sure we're coming from the payment page
          $_SESSION['insurance'] = 0;
        }
    }
    
    function check()
    {
        global $db;
        if (!isset($this->check)) {
        $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_ORDER_TOTAL_INSURANCE_STATUS'");
        $this->check = $check_query->RecordCount();
        }
        
        return $this->check;
    }
    
    function keys()
    {
      $keys = array('MODULE_ORDER_TOTAL_INSURANCE_STATUS', 'MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER', 'MODULE_ORDER_TOTAL_INSURANCE_TABLE', 'MODULE_ORDER_TOTAL_INSURANCE_TYPE', 'MODULE_ORDER_TOTAL_INSURANCE_PER', 'MODULE_ORDER_TOTAL_INSURANCE_FEE', 'MODULE_ORDER_TOTAL_INSURANCE_INCREMENT', 'MODULE_ORDER_TOTAL_INSURANCE_OVER', 'MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS', 'MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL', 'MODULE_ORDER_TOTAL_INSURANCE_GV', 'MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING', 'MODULE_ORDER_TOTAL_INSURANCE_REQUIRED');
      
      for ($i=1; $i<=$this->num_zones; $i++) {
				$keys[] = 'MODULE_ORDER_TOTAL_INSURANCE_ZONE_' . $i;
				$keys[] = 'MODULE_ORDER_TOTAL_INSURANCE_COST_' . $i;
			}
			return $keys;
    }
    
    function install()
    {
      global $db;
			$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Insurance Module', 'MODULE_ORDER_TOTAL_INSURANCE_STATUS', 'true', 'Do you want to enable this module? To fully turn this off, both this option and the one below should be set to false.', '6', '1','zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
			$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values('Sort Order', 'MODULE_ORDER_TOTAL_INSURANCE_SORT_ORDER', '299', 'Sort order of display. Note: Must be higher than the sub-total.', '6', '3', now())");
			$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values('Use Table Rates?', 'MODULE_ORDER_TOTAL_INSURANCE_TABLE', 'true', 'Do you want to use Table Rates?', '6', '4', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values('Alternate Insurance Type', 'MODULE_ORDER_TOTAL_INSURANCE_TYPE', 'percent', 'If not using USPS rates, would you like to charge by percentage of cart total, or by a specific amount?', '6', '5', 'zen_cfg_select_option(array(\'percent\', \'amount\'), ', now())");
			$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, date_added) values('Insurance Percentage', 'MODULE_ORDER_TOTAL_INSURANCE_PER', '5', 'If using percent, what percentage of subtotal to charge for insurance.', '6', '6', '', now())");
			$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, date_added) values('Insurance Rate', 'MODULE_ORDER_TOTAL_INSURANCE_FEE', '.50', 'What amount do you want to charge per increment amount.', '6', '7', 'currencies->format', now())");
			$db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, date_added) values('Increment Amount', 'MODULE_ORDER_TOTAL_INSURANCE_INCREMENT', '100', 'For each <b>how many dollars,</b> ie. the increment amount,  of the total(e.g. 100 here and .50 for the rate above would mean 50 cents fee for every 100 of the amount to be insured).', '6', '8', 'currencies->format', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, date_added) values('Amount Exempt From Fee', 'MODULE_ORDER_TOTAL_INSURANCE_OVER', '100', '(Works with INCREMENT and TABLE RATE amounts) Set this to the part of the total that is exempt from the Shipping Insurance.  (ie. set to 100 for all orders under 100 to be exempt, already insured, etc.)', '6', '9', 'currencies->format', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values('Tax Class', 'MODULE_ORDER_TOTAL_INSURANCE_TAX_CLASS', '0', 'Use the following tax class on the insurance fee.', '6', '10', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values('No Insurance Fee on Virtual Products', 'MODULE_ORDER_TOTAL_INSURANCE_VIRTUAL', 'true', 'Do not charge insurance fee when cart is Virtual Products Only', '6', '11', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values('No Insurance Fee on Gift Vouchers', 'MODULE_ORDER_TOTAL_INSURANCE_GV', 'true', 'Do not charge insurance fee when cart is Gift Vouchers only', '6', '12', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values('No Insurance Fee on Free Shipping', 'MODULE_ORDER_TOTAL_INSURANCE_FREE_SHIPPING', 'true', 'Do not calculate insurance for products that have free shipping (includes gv and virtual products)', '6', '13', 'zen_cfg_select_option(array(\'true\', \'false\'), ', now())");
	    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, date_added) values('Required Insurance Amount', 'MODULE_ORDER_TOTAL_INSURANCE_REQUIRED', '100', 'Automatically charge shipping insurance for amounts over X dollars', 6, '14', 'currencies->format', now())");
	   	for ($i = 1; $i <= $this->num_zones; $i++) {
        if ($i == 1) {
          $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Insurance Zone " . $i . "', 'MODULE_ORDER_TOTAL_INSURANCE_ZONE_" . $i . "', '0', 'If a zone is selected, only enable this insurance for that zone (Note: use this field for non-table rates as well).', '6', '0', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
        } else {	
			    $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Insurance Zone " . $i . "', 'MODULE_ORDER_TOTAL_INSURANCE_ZONE_" . $i . "', '0', 'If a zone is selected, only enable this insurance for that zone.', '6', '0', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
			  }
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Zone " . $i ." Insurance Table', 'MODULE_ORDER_TOTAL_INSURANCE_COST_" . $i ."', '50:1.70,100:2.15,200:2.60,300:4.60,400:5.55,500:6.50,600:7.45', 'The insurance cost is based on the total cost of the items. Example: 25:8.50,50:5.50,etc.. Up to 25 charge 8.50, from there to 50 charge 5.50, etc', '6', '0', 'zen_cfg_textarea(', now())");
			}      
    }
    
		function remove() {
			global $db;
			$db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
		}
	}
}
?>