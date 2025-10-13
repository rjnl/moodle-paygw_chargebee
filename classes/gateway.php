<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Contains class for Chargebee payment gateway.
 *
 * @package    paygw_chargebee
 * @copyright  2022 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_chargebee;

use core_payment\form\account_gateway;

/**
 * The gateway class for Chargebee payment gateway.
 *
 * @copyright  2022 Rajneel Totaram <rajneel.totaram@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway extends \core_payment\gateway {
    /**
     * The full list of currencies supported by Chargebee.
     * Only certain currencies are supported based on the payment gateway and currencies enabled,
     * the plugin does not account for that when giving the list of supported currencies.
     *
     * {@link https://www.chargebee.com/docs/2.0/supported-currencies.html}
     *
     * @return string[]
     */
    public static function get_supported_currencies(): array {
        return [
            'USD', 'AED', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BMD', 'BND', 'BOB', 'BRL',
            'BSD', 'BWP', 'BYR', 'BZD', 'CAD', 'CDF', 'CHF', 'CLF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DKK', 'DOP',
            'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GHS', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HUF',
            'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KRW', 'KYD', 'LBP', 'LKR', 'LRD', 'MAD', 'MDL', 'MKD',
            'MMK', 'MOP', 'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'PAB',
            'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR', 'SBD', 'SEK', 'SGD', 'SHP', 'SLL', 'SOS',
            'SRD', 'STD', 'SYP', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'UYU', 'UZS', 'VEF', 'VND',
            'WST', 'XAF', 'XCD', 'XOF', 'ZAR', 'ZMW', 'ZWL',
        ];
    }

    /**
     * The list of zero/non-decimal currencies in Chargebee.
     *
     * {@link https://www.chargebee.com/docs/2.0/supported-currencies.html}
     *
     * @return string[]
     */
    public static function get_zero_decimal_currencies(): array {
        return [
            'CLP', 'JPY', 'KRW', 'VND', 'XAF', 'XOF',
        ];
    }

    /**
     * Configuration form for the gateway instance
     *
     * Use $form->get_mform() to access the \MoodleQuickForm instance
     *
     * @param account_gateway $form
     */
    public static function add_configuration_to_gateway_form(account_gateway $form): void {
        $mform = $form->get_mform();

        $mform->addElement('text', 'sitename', get_string('sitename', 'paygw_chargebee'));
        $mform->setType('sitename', PARAM_TEXT);
        $mform->addHelpButton('sitename', 'sitename', 'paygw_chargebee');

        $mform->addElement('text', 'apikey', get_string('apikey', 'paygw_chargebee'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'paygw_chargebee');

        $mform->addElement('text', 'customeridprefix', get_string('customeridprefix', 'paygw_chargebee'));
        $mform->setType('customeridprefix', PARAM_TEXT);
        $mform->addHelpButton('customeridprefix', 'customeridprefix', 'paygw_chargebee');

        $mform->addElement('text', 'lineitemprefix', get_string('lineitemprefix', 'paygw_chargebee'));
        $mform->setType('lineitemprefix', PARAM_TEXT);
        $mform->addHelpButton('lineitemprefix', 'lineitemprefix', 'paygw_chargebee');

        $mform->addElement('selectyesno', 'autovoidinvoice', get_string('autovoidinvoice', 'paygw_chargebee'));
        $mform->setType('autovoidinvoice', PARAM_INT);
        $mform->addHelpButton('autovoidinvoice', 'autovoidinvoice', 'paygw_chargebee');
    }

    /**
     * Validates the gateway configuration form.
     *
     * @param account_gateway $form
     * @param \stdClass $data
     * @param array $files
     * @param array $errors form errors (passed by reference)
     */
    public static function validate_gateway_form(account_gateway $form, \stdClass $data, array $files, array &$errors): void {
        if ($data->enabled && (empty($data->apikey) || empty($data->sitename))) {
            $errors['enabled'] = get_string('gatewaycannotbeenabled', 'payment');
        }
    }
}
