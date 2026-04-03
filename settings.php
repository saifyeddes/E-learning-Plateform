<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins',
        new admin_externalpage(
            'elearning_system_dashboard',
            'E-learning System',
            new moodle_url('/local/elearning_system/admin/dashboard.php'),
            'local/elearning_system:manage'
        )
    );

    $settings = new admin_settingpage(
        'local_elearning_system_paymentsettings',
        get_string('paymentsettings', 'local_elearning_system')
    );

    $settings->add(new admin_setting_configselect(
        'local_elearning_system/payment_provider',
        get_string('paymentprovider', 'local_elearning_system'),
        get_string('paymentprovider_desc', 'local_elearning_system'),
        'stripe',
        [
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_elearning_system/simulate_success',
        get_string('simulatesuccess', 'local_elearning_system'),
        get_string('simulatesuccess_desc', 'local_elearning_system'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_elearning_system/stripeheading',
        get_string('stripepaymentsettings', 'local_elearning_system'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/stripe_public_key',
        get_string('stripepublickey', 'local_elearning_system'),
        get_string('stripepublickey_desc', 'local_elearning_system'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/stripe_secret_key',
        get_string('stripesecretkey', 'local_elearning_system'),
        get_string('stripesecretkey_desc', 'local_elearning_system'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/stripe_currency',
        get_string('stripecurrency', 'local_elearning_system'),
        get_string('stripecurrency_desc', 'local_elearning_system'),
        'usd',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_heading(
        'local_elearning_system/paypalheading',
        get_string('paypalpaymentsettings', 'local_elearning_system'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/paypal_business_email',
        get_string('paypalbusinessemail', 'local_elearning_system'),
        get_string('paypalbusinessemail_desc', 'local_elearning_system'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_elearning_system/paypal_sandbox',
        get_string('paypalsandbox', 'local_elearning_system'),
        get_string('paypalsandbox_desc', 'local_elearning_system'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/paypal_currency',
        get_string('paypalcurrency', 'local_elearning_system'),
        get_string('paypalcurrency_desc', 'local_elearning_system'),
        'usd',
        PARAM_ALPHANUMEXT
    ));

    $ADMIN->add('localplugins', $settings);
}