<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/authlib.php');
require_once($CFG->dirroot . '/login/lib.php');

global $DB, $CFG;

$token = optional_param('token', '', PARAM_ALPHANUM);

if (!empty($token)) {
    redirect(new moodle_url('/local/elearning_system/set_password.php', [
        'token' => $token,
    ]));
}

if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/my/'));
}

$lang = current_language();
$isrtl = right_to_left();

$texts = [
    'en' => [
        'title' => 'Forgot Password',
        'intro' => 'Enter your email address and we will send you a secure link to reset your password.',
        'email' => 'Email address',
        'placeholder' => 'your@email.com',
        'send' => 'Send reset link',
        'back' => 'Back to login',
        'paneltitle' => 'Reset your password',
        'panelsubtitle' => 'Secure access to your learning space',
        'success' => 'If this email exists, a secure reset link has been sent.',
        'error' => 'Unable to process the request. Please try again.',
        'subject' => 'Reset your password',
        'hello' => 'Hello',
        'mailintro' => 'A password reset request was made for your account.',
        'maillink' => 'Click here to set a new password',
        'mailignore' => 'If you did not request this, you can ignore this email.',
    ],
    'fr' => [
        'title' => 'Mot de passe oublié',
        'intro' => 'Saisissez votre adresse e-mail et nous vous enverrons un lien sécurisé pour réinitialiser votre mot de passe.',
        'email' => 'Adresse e-mail',
        'placeholder' => 'votre@email.com',
        'send' => 'Envoyer le lien',
        'back' => 'Retour à la connexion',
        'paneltitle' => 'Réinitialiser votre mot de passe',
        'panelsubtitle' => 'Accès sécurisé à votre espace d’apprentissage',
        'success' => 'Si cet e-mail existe, un lien sécurisé de réinitialisation a été envoyé.',
        'error' => 'Impossible de traiter la demande. Veuillez réessayer.',
        'subject' => 'Réinitialisation du mot de passe',
        'hello' => 'Bonjour',
        'mailintro' => 'Une demande de réinitialisation du mot de passe a été effectuée pour votre compte.',
        'maillink' => 'Cliquez ici pour définir un nouveau mot de passe',
        'mailignore' => 'Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.',
    ],
    'ar' => [
        'title' => 'نسيت كلمة المرور',
        'intro' => 'أدخل بريدك الإلكتروني وسنرسل لك رابطًا آمنًا لإعادة تعيين كلمة المرور.',
        'email' => 'البريد الإلكتروني',
        'placeholder' => 'your@email.com',
        'send' => 'إرسال رابط التعيين',
        'back' => 'العودة إلى تسجيل الدخول',
        'paneltitle' => 'إعادة تعيين كلمة المرور',
        'panelsubtitle' => 'وصول آمن إلى مساحة التعلم الخاصة بك',
        'success' => 'إذا كان هذا البريد الإلكتروني موجودًا، فقد تم إرسال رابط آمن لإعادة التعيين.',
        'error' => 'تعذر معالجة الطلب. يرجى المحاولة مرة أخرى.',
        'subject' => 'إعادة تعيين كلمة المرور',
        'hello' => 'مرحبًا',
        'mailintro' => 'تم تقديم طلب لإعادة تعيين كلمة المرور لحسابك.',
        'maillink' => 'اضغط هنا لتعيين كلمة مرور جديدة',
        'mailignore' => 'إذا لم تطلب ذلك، يمكنك تجاهل هذا البريد الإلكتروني.',
    ],
];

$t = $texts['en'];

if (str_starts_with($lang, 'fr')) {
    $t = $texts['fr'];
} else if (str_starts_with($lang, 'ar')) {
    $t = $texts['ar'];
}

$message = '';
$messageclass = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $email = required_param('email', PARAM_EMAIL);

    $message = $t['success'];
    $messageclass = 'success';

    try {
        $user = $DB->get_record('user', [
            'email' => $email,
            'deleted' => 0,
            'suspended' => 0,
        ], '*', IGNORE_MULTIPLE);

        if ($user && !isguestuser($user)) {
            $resetrecord = core_login_generate_password_reset($user);

            $reseturl = new moodle_url('/local/elearning_system/set_password.php', [
                'token' => $resetrecord->token,
            ]);

            $site = get_site();
            $subject = $t['subject'] . ' - ' . format_string($site->fullname);

            $bodytext =
                $t['hello'] . ' ' . fullname($user) . ",\n\n" .
                $t['mailintro'] . "\n\n" .
                $reseturl->out(false) . "\n\n" .
                $t['mailignore'] . "\n\n" .
                format_string($site->fullname);

            $bodyhtml =
                '<p>' . s($t['hello']) . ' ' . s(fullname($user)) . ',</p>' .
                '<p>' . s($t['mailintro']) . '</p>' .
                '<p><a href="' . s($reseturl->out(false)) . '">' . s($t['maillink']) . '</a></p>' .
                '<p>' . s($t['mailignore']) . '</p>' .
                '<p>' . s(format_string($site->fullname)) . '</p>';

            email_to_user(
                $user,
                core_user::get_noreply_user(),
                $subject,
                $bodytext,
                $bodyhtml
            );
        }

    } catch (Throwable $e) {
        $message = $t['error'];
        $messageclass = 'error';
    }
}

?>
<!DOCTYPE html>
<html lang="<?php echo s($lang); ?>" dir="<?php echo $isrtl ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo s($t['title']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            font-family: "Poppins", "Segoe UI", Arial, sans-serif;
            background: #eef5fb;
        }

        .es-forgot-page {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 36px;
            background:
                radial-gradient(circle at 10% 15%, rgba(97, 184, 240, 0.18), transparent 34%),
                radial-gradient(circle at 90% 85%, rgba(72, 181, 150, 0.12), transparent 30%),
                linear-gradient(135deg, #f7fbff 0%, #edf5ff 48%, #ffffff 100%);
        }

        .es-forgot-card {
            width: min(1120px, calc(100vw - 72px));
            height: min(610px, calc(100vh - 24px));
            display: grid;
            grid-template-columns: 440px 1fr;
            background: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(16, 42, 67, 0.14);
        }

        .es-forgot-left {
            padding: 4rem 3.2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .es-forgot-left h1 {
            margin: 0 0 0.65rem;
            color: #102a43;
            font-size: 1.65rem;
            font-weight: 900;
        }

        .es-forgot-left p {
            margin: 0 0 1.35rem;
            color: #53657a;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        .es-alert {
            border-radius: 0.85rem;
            padding: 0.85rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .es-alert.success {
            background: #e7f8ef;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .es-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .es-forgot-field {
            margin-bottom: 0.95rem;
        }

        .es-forgot-field label {
            display: block;
            margin-bottom: 0.35rem;
            color: #102a43;
            font-size: 0.82rem;
            font-weight: 800;
        }

        .es-forgot-field input {
            width: 100%;
            height: 44px;
            border: 1px solid #d5e0ec;
            border-radius: 0.85rem;
            padding: 0 1rem;
            color: #102a43;
            background: #ffffff;
            font-size: 0.95rem;
            outline: none;
        }

        .es-forgot-field input:focus {
            border-color: #006794;
            box-shadow: 0 0 0 0.18rem rgba(0, 103, 148, 0.12);
        }

        .es-forgot-submit {
            width: 100%;
            height: 45px;
            border: 0;
            border-radius: 0.85rem;
            background: linear-gradient(135deg, #006794, #3ca8ef);
            color: #ffffff;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 12px 26px rgba(0, 103, 148, 0.22);
        }

        .es-forgot-submit:hover {
            filter: brightness(0.96);
        }

        .es-forgot-back {
            display: block;
            margin-top: 1rem;
            text-align: right;
            color: #006794;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
        }

        .es-forgot-back:hover {
            text-decoration: underline;
        }

        .es-forgot-right {
            position: relative;
            background:
                radial-gradient(circle at 18% 20%, rgba(255,255,255,0.13), transparent 0 90px, transparent 91px),
                radial-gradient(circle at 92% 88%, rgba(255,255,255,0.18), transparent 0 175px, transparent 176px),
                linear-gradient(135deg, #07314f 0%, #006794 52%, #45aeea 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            text-align: center;
            overflow: hidden;
        }

        .es-forgot-right::before {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            border-radius: 50%;
            top: 45px;
            left: 45px;
            background: rgba(255,255,255,0.09);
        }

        .es-forgot-right::after {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            border-radius: 50%;
            right: -80px;
            bottom: -95px;
            background: rgba(255,255,255,0.15);
        }

        .es-forgot-visual-content {
            position: relative;
            z-index: 2;
            padding: 2rem;
        }

        .es-forgot-icon {
            width: 118px;
            height: 118px;
            margin: 0 auto 1.4rem;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.6rem;
        }

        .es-forgot-right h2 {
            margin: 0 0 0.6rem;
            color: #ffffff;
            font-size: 1.45rem;
            font-weight: 900;
        }

        .es-forgot-right p {
            margin: 0;
            color: rgba(255,255,255,0.92);
            line-height: 1.7;
        }

        [dir="rtl"] .es-forgot-back {
            text-align: left;
        }

        @media (max-width: 900px) {
            .es-forgot-page {
                padding: 0.8rem;
            }

            .es-forgot-card {
                width: 100%;
                height: auto;
                min-height: calc(100vh - 1.6rem);
                grid-template-columns: 1fr;
                border-radius: 20px;
            }

            .es-forgot-right {
                min-height: 240px;
                order: -1;
            }

            .es-forgot-left {
                padding: 2rem 1.4rem;
            }
        }
    </style>
</head>

<body>
    <main class="es-forgot-page">
        <section class="es-forgot-card">

            <div class="es-forgot-left">
                <h1><?php echo s($t['title']); ?></h1>

                <p><?php echo s($t['intro']); ?></p>

                <?php if ($message !== ''): ?>
                    <div class="es-alert <?php echo s($messageclass); ?>">
                        <?php echo s($message); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo (new moodle_url('/local/elearning_system/forgot_password.php'))->out(false); ?>">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                    <div class="es-forgot-field">
                        <label for="email"><?php echo s($t['email']); ?></label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="<?php echo s($t['placeholder']); ?>"
                            required
                            autocomplete="email"
                        >
                    </div>

                    <button type="submit" class="es-forgot-submit">
                        <?php echo s($t['send']); ?>
                    </button>
                </form>

                <a class="es-forgot-back" href="<?php echo $CFG->wwwroot; ?>/login/index.php">
                    <?php echo s($t['back']); ?>
                </a>
            </div>

            <div class="es-forgot-right">
                <div class="es-forgot-visual-content">
                    <div class="es-forgot-icon">🔒</div>
                    <h2><?php echo s($t['paneltitle']); ?></h2>
                    <p><?php echo s($t['panelsubtitle']); ?></p>
                </div>
            </div>

        </section>
    </main>
</body>
</html>