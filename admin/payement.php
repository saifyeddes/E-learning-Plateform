<?php

require('../../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/elearning_system:manage', $context);

$PAGE->set_context($context);
$PAGE->set_url('/local/elearning_system/admin/payement.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Payement TVA');
$PAGE->set_heading('Payement TVA');

global $DB;

/**
 * Return ISO currency options for admin selection.
 *
 * @return array<string, string>
 */
function local_elearning_system_currency_labels(): array {
    return [
        'AED' => 'AED - UAE Dirham',
        'AFN' => 'AFN - Afghan Afghani',
        'ALL' => 'ALL - Albanian Lek',
        'AMD' => 'AMD - Armenian Dram',
        'ANG' => 'ANG - Netherlands Antillean Guilder',
        'AOA' => 'AOA - Angolan Kwanza',
        'ARS' => 'ARS - Argentine Peso',
        'AUD' => 'AUD - Australian Dollar',
        'AWG' => 'AWG - Aruban Florin',
        'AZN' => 'AZN - Azerbaijani Manat',
        'BAM' => 'BAM - Bosnia and Herzegovina Convertible Mark',
        'BBD' => 'BBD - Barbadian Dollar',
        'BDT' => 'BDT - Bangladeshi Taka',
        'BGN' => 'BGN - Bulgarian Lev',
        'BHD' => 'BHD - Bahraini Dinar',
        'BIF' => 'BIF - Burundian Franc',
        'BMD' => 'BMD - Bermudian Dollar',
        'BND' => 'BND - Brunei Dollar',
        'BOB' => 'BOB - Bolivian Boliviano',
        'BRL' => 'BRL - Brazilian Real',
        'BSD' => 'BSD - Bahamian Dollar',
        'BTN' => 'BTN - Bhutanese Ngultrum',
        'BWP' => 'BWP - Botswana Pula',
        'BYN' => 'BYN - Belarusian Ruble',
        'BZD' => 'BZD - Belize Dollar',
        'CAD' => 'CAD - Canadian Dollar',
        'CDF' => 'CDF - Congolese Franc',
        'CHF' => 'CHF - Swiss Franc',
        'CLP' => 'CLP - Chilean Peso',
        'CNY' => 'CNY - Chinese Yuan',
        'COP' => 'COP - Colombian Peso',
        'CRC' => 'CRC - Costa Rican Colon',
        'CUP' => 'CUP - Cuban Peso',
        'CVE' => 'CVE - Cape Verdean Escudo',
        'CZK' => 'CZK - Czech Koruna',
        'DJF' => 'DJF - Djiboutian Franc',
        'DKK' => 'DKK - Danish Krone',
        'DOP' => 'DOP - Dominican Peso',
        'DZD' => 'DZD - Algerian Dinar',
        'EGP' => 'EGP - Egyptian Pound',
        'ERN' => 'ERN - Eritrean Nakfa',
        'ETB' => 'ETB - Ethiopian Birr',
        'EUR' => 'EUR - Euro',
        'FJD' => 'FJD - Fijian Dollar',
        'FKP' => 'FKP - Falkland Islands Pound',
        'GBP' => 'GBP - British Pound Sterling',
        'GEL' => 'GEL - Georgian Lari',
        'GHS' => 'GHS - Ghanaian Cedi',
        'GIP' => 'GIP - Gibraltar Pound',
        'GMD' => 'GMD - Gambian Dalasi',
        'GNF' => 'GNF - Guinean Franc',
        'GTQ' => 'GTQ - Guatemalan Quetzal',
        'GYD' => 'GYD - Guyanese Dollar',
        'HKD' => 'HKD - Hong Kong Dollar',
        'HNL' => 'HNL - Honduran Lempira',
        'HTG' => 'HTG - Haitian Gourde',
        'HUF' => 'HUF - Hungarian Forint',
        'IDR' => 'IDR - Indonesian Rupiah',
        'ILS' => 'ILS - Israeli New Shekel',
        'INR' => 'INR - Indian Rupee',
        'IQD' => 'IQD - Iraqi Dinar',
        'IRR' => 'IRR - Iranian Rial',
        'ISK' => 'ISK - Icelandic Krona',
        'JMD' => 'JMD - Jamaican Dollar',
        'JOD' => 'JOD - Jordanian Dinar',
        'JPY' => 'JPY - Japanese Yen',
        'KES' => 'KES - Kenyan Shilling',
        'KGS' => 'KGS - Kyrgyzstani Som',
        'KHR' => 'KHR - Cambodian Riel',
        'KMF' => 'KMF - Comorian Franc',
        'KPW' => 'KPW - North Korean Won',
        'KRW' => 'KRW - South Korean Won',
        'KWD' => 'KWD - Kuwaiti Dinar',
        'KYD' => 'KYD - Cayman Islands Dollar',
        'KZT' => 'KZT - Kazakhstani Tenge',
        'LAK' => 'LAK - Lao Kip',
        'LBP' => 'LBP - Lebanese Pound',
        'LKR' => 'LKR - Sri Lankan Rupee',
        'LRD' => 'LRD - Liberian Dollar',
        'LSL' => 'LSL - Lesotho Loti',
        'LYD' => 'LYD - Libyan Dinar',
        'MAD' => 'MAD - Moroccan Dirham',
        'MDL' => 'MDL - Moldovan Leu',
        'MGA' => 'MGA - Malagasy Ariary',
        'MKD' => 'MKD - Macedonian Denar',
        'MMK' => 'MMK - Myanmar Kyat',
        'MNT' => 'MNT - Mongolian Tugrik',
        'MOP' => 'MOP - Macanese Pataca',
        'MRU' => 'MRU - Mauritanian Ouguiya',
        'MUR' => 'MUR - Mauritian Rupee',
        'MVR' => 'MVR - Maldivian Rufiyaa',
        'MWK' => 'MWK - Malawian Kwacha',
        'MXN' => 'MXN - Mexican Peso',
        'MYR' => 'MYR - Malaysian Ringgit',
        'MZN' => 'MZN - Mozambican Metical',
        'NAD' => 'NAD - Namibian Dollar',
        'NGN' => 'NGN - Nigerian Naira',
        'NIO' => 'NIO - Nicaraguan Cordoba',
        'NOK' => 'NOK - Norwegian Krone',
        'NPR' => 'NPR - Nepalese Rupee',
        'NZD' => 'NZD - New Zealand Dollar',
        'OMR' => 'OMR - Omani Rial',
        'PAB' => 'PAB - Panamanian Balboa',
        'PEN' => 'PEN - Peruvian Sol',
        'PGK' => 'PGK - Papua New Guinean Kina',
        'PHP' => 'PHP - Philippine Peso',
        'PKR' => 'PKR - Pakistani Rupee',
        'PLN' => 'PLN - Polish Zloty',
        'PYG' => 'PYG - Paraguayan Guarani',
        'QAR' => 'QAR - Qatari Riyal',
        'RON' => 'RON - Romanian Leu',
        'RSD' => 'RSD - Serbian Dinar',
        'RUB' => 'RUB - Russian Ruble',
        'RWF' => 'RWF - Rwandan Franc',
        'SAR' => 'SAR - Saudi Riyal',
        'SBD' => 'SBD - Solomon Islands Dollar',
        'SCR' => 'SCR - Seychellois Rupee',
        'SDG' => 'SDG - Sudanese Pound',
        'SEK' => 'SEK - Swedish Krona',
        'SGD' => 'SGD - Singapore Dollar',
        'SHP' => 'SHP - Saint Helena Pound',
        'SLE' => 'SLE - Sierra Leonean Leone',
        'SOS' => 'SOS - Somali Shilling',
        'SRD' => 'SRD - Surinamese Dollar',
        'SSP' => 'SSP - South Sudanese Pound',
        'STN' => 'STN - Sao Tome and Principe Dobra',
        'SVC' => 'SVC - Salvadoran Colon',
        'SYP' => 'SYP - Syrian Pound',
        'SZL' => 'SZL - Swazi Lilangeni',
        'THB' => 'THB - Thai Baht',
        'TJS' => 'TJS - Tajikistani Somoni',
        'TMT' => 'TMT - Turkmenistani Manat',
        'TND' => 'TND - Tunisian Dinar',
        'TOP' => 'TOP - Tongan Paanga',
        'TRY' => 'TRY - Turkish Lira',
        'TTD' => 'TTD - Trinidad and Tobago Dollar',
        'TWD' => 'TWD - New Taiwan Dollar',
        'TZS' => 'TZS - Tanzanian Shilling',
        'UAH' => 'UAH - Ukrainian Hryvnia',
        'UGX' => 'UGX - Ugandan Shilling',
        'USD' => 'USD - US Dollar',
        'UYU' => 'UYU - Uruguayan Peso',
        'UZS' => 'UZS - Uzbekistani Som',
        'VES' => 'VES - Venezuelan Bolivar',
        'VND' => 'VND - Vietnamese Dong',
        'VUV' => 'VUV - Vanuatu Vatu',
        'WST' => 'WST - Samoan Tala',
        'XAF' => 'XAF - CFA Franc BEAC',
        'XCD' => 'XCD - East Caribbean Dollar',
        'XOF' => 'XOF - CFA Franc BCEAO',
        'XPF' => 'XPF - CFP Franc',
        'YER' => 'YER - Yemeni Rial',
        'ZAR' => 'ZAR - South African Rand',
        'ZMW' => 'ZMW - Zambian Kwacha',
        'ZWL' => 'ZWL - Zimbabwean Dollar',
    ];
}

$errors = [];
$successmessage = '';

$rawtvapercent = trim((string)optional_param('tvapercent', '', PARAM_RAW_TRIMMED));
$rawcurrency = core_text::strtoupper(trim((string)optional_param('currency', '', PARAM_ALPHANUMEXT)));
$currencylabels = local_elearning_system_currency_labels();
$defaultcurrency = core_text::strtoupper(trim((string)get_config('local_elearning_system', 'stripe_currency')));
if ($defaultcurrency === '' || !isset($currencylabels[$defaultcurrency])) {
    $defaultcurrency = 'USD';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $tvapercent = 0.0;
    if ($rawtvapercent !== '') {
        if (!is_numeric($rawtvapercent)) {
            $errors[] = 'TVA must be a valid number.';
        } else {
            $tvapercent = (float)$rawtvapercent;
        }
    }

    if ($tvapercent < 0 || $tvapercent > 100) {
        $errors[] = 'TVA must be between 0 and 100.';
    }

    $selectedcurrency = $rawcurrency !== '' ? $rawcurrency : $defaultcurrency;
    if (!isset($currencylabels[$selectedcurrency])) {
        $errors[] = 'Please select a valid currency.';
    }

    if (empty($errors)) {
        set_config('vat_percent', $tvapercent, 'local_elearning_system');
        // Apply one global currency across payment providers.
        set_config('stripe_currency', core_text::strtolower($selectedcurrency), 'local_elearning_system');
        set_config('paypal_currency', core_text::strtolower($selectedcurrency), 'local_elearning_system');
        $successmessage = 'TVA and currency updated successfully.';
    }
}

$configtvapercent = (float)get_config('local_elearning_system', 'vat_percent');
if ($configtvapercent < 0 || $configtvapercent > 100) {
    $configtvapercent = 0.0;
}

$tvapercentfordisplay = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors))
    ? ($rawtvapercent === '' ? '0' : s($rawtvapercent))
    : number_format($configtvapercent, 2, '.', '');

$currentcurrency = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors) && $rawcurrency !== '')
    ? $rawcurrency
    : $defaultcurrency;

$currencyoptions = [];
foreach ($currencylabels as $code => $label) {
    $currencyoptions[] = [
        'value' => $code,
        'label' => $label,
        'selected' => $currentcurrency === $code,
    ];
}

$templatedata = [
    'dashboardurl' => (new moodle_url('/local/elearning_system/admin/dashboard.php'))->out(false),
    'productsurl' => (new moodle_url('/local/elearning_system/admin/products.php'))->out(false),
    'ordersurl' => (new moodle_url('/local/elearning_system/admin/orders.php'))->out(false),
    'couponsurl' => (new moodle_url('/local/elearning_system/admin/coupons.php'))->out(false),
    'payementurl' => (new moodle_url('/local/elearning_system/admin/payement.php'))->out(false),

    'isdashboard' => false,
    'isproducts' => false,
    'isorders' => false,
    'iscoupons' => false,
    'ispayement' => true,

    'errors' => $errors,
    'haserrors' => !empty($errors),
    'successmessage' => $successmessage,
    'hassuccessmessage' => $successmessage !== '',
    'tvapercent' => $tvapercentfordisplay,
    'currencyoptions' => $currencyoptions,
    'sesskey' => sesskey(),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_elearning_system/admin_layout', $templatedata);
echo $OUTPUT->footer();
