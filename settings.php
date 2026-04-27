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

    $settings->add(new admin_setting_heading(
        'local_elearning_system/llmheading',
        get_string('llmsettings', 'local_elearning_system'),
        get_string('llmsettings_desc', 'local_elearning_system')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_elearning_system/llm_enabled',
        get_string('llmenabled', 'local_elearning_system'),
        get_string('llmenabled_desc', 'local_elearning_system'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'local_elearning_system/llm_provider',
        get_string('llmprovider', 'local_elearning_system'),
        get_string('llmprovider_desc', 'local_elearning_system'),
        'openai',
        [
            'openai' => 'OpenAI',
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/llm_model',
        get_string('llmmodel', 'local_elearning_system'),
        get_string('llmmodel_desc', 'local_elearning_system'),
        'gpt-4o-mini',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/llm_endpoint',
        get_string('llmendpoint', 'local_elearning_system'),
        get_string('llmendpoint_desc', 'local_elearning_system'),
        'https://api.openai.com/v1/chat/completions',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_elearning_system/llm_api_key',
        get_string('llmapikey', 'local_elearning_system'),
        get_string('llmapikey_desc', 'local_elearning_system'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/llm_timeout',
        get_string('llmtimeout', 'local_elearning_system'),
        get_string('llmtimeout_desc', 'local_elearning_system'),
        '8',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_elearning_system/llmconfidence',
        get_string('llmconfidence', 'local_elearning_system'),
        get_string('llmconfidence_desc', 'local_elearning_system'),
        '0.60',
        PARAM_FLOAT
    ));

    $ADMIN->add('localplugins', $settings);
}