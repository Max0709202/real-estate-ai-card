<?php

/**
 * PHPMailer - PHP email creation and transport class.
 * PHP Version 5.5
 * @package PHPMailer
 * @see https://github.com/PHPMailer/PHPMailer/ The PHPMailer GitHub project
 * @author Marcus Bointon (Synchro/coolbru) <phpmailer@synchromedia.co.uk>
 * @author Jim Jagielski (jimjag) <jimjag@gmail.com>
 * @author Andy Prevost (codeworxtech) <codeworxtech@users.sourceforge.net>
 * @author Brent R. Matzelle (original founder)
 * @copyright 2012 - 2020 Marcus Bointon
 * @copyright 2010 - 2012 Jim Jagielski
 * @copyright 2004 - 2009 Andy Prevost
 * @license https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html GNU Lesser General Public License
 * @note This program is distributed in the hope that it will be useful - WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * Get an OAuth2 token from an OAuth2 provider.
 * * Install this script on your server so that it's accessible
 * as [https/http]://<yourdomain>/<folder>/get_oauth_token.php
 * e.g.: http://localhost/phpmailer/get_oauth_token.php
 * * Ensure dependencies are installed with 'composer install'
 * * Set up an app in your Google/Yahoo/Microsoft account
 * * Set the script address as the app's redirect URL
 * If no refresh token is obtained when running this file,
 * revoke access to your app and run the script again.
 */

namespace PHPMailer\PHPMailer;

/**
 * Aliases for League Provider Classes
 * Make sure you have added these to your composer.json and run `composer install`
 * Plenty to choose from here:
 * @see https://oauth2-client.thephpleague.com/providers/thirdparty/
 */
//@see https://github.com/thephpleague/oauth2-google
use League\OAuth2\Client\Provider\Google;
//@see https://packagist.org/packages/hayageek/oauth2-yahoo
use Hayageek\OAuth2\Client\Provider\Yahoo;
//@see https://github.com/stevenmaguire/oauth2-microsoft
use Stevenmaguire\OAuth2\Client\Provider\Microsoft;
//@see https://github.com/greew/oauth2-azure-provider
use Greew\OAuth2\Client\Provider\Azure;

if (!isset($_GET['code']) && !isset($_POST['provider'])) {
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>OAuth Token Setup</title>
  <style>
    :root {
      --bg: #f4f6fb;
      --card: #ffffff;
      --text: #1f2937;
      --muted: #6b7280;
      --line: #e5e7eb;
      --primary: #0f62fe;
      --primary-hover: #0b4fd1;
      --ring: rgba(15, 98, 254, 0.2);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: "Segoe UI", "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif;
      color: var(--text);
      background: linear-gradient(180deg, #f7f9ff 0%, var(--bg) 100%);
      min-height: 100vh;
      padding: 24px 12px;
    }

    .wrap {
      max-width: 720px;
      margin: 0 auto;
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 14px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
      padding: 24px;
    }

    h1 {
      margin: 0 0 12px;
      font-size: 1.25rem;
      line-height: 1.4;
      font-weight: 700;
    }

    p {
      margin: 0 0 14px;
      color: var(--muted);
      line-height: 1.7;
      font-size: 0.95rem;
    }

    .provider-group {
      display: grid;
      grid-template-columns: repeat(2, minmax(140px, 1fr));
      gap: 10px;
      margin: 12px 0 24px;
    }

    .provider-item {
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 12px;
      display: flex;
      align-items: center;
      gap: 10px;
      background: #fff;
    }

    .field {
      margin-bottom: 14px;
    }

    .field label {
      display: block;
      font-size: 0.88rem;
      font-weight: 600;
      margin-bottom: 6px;
    }

    input[type="text"] {
      width: 100%;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      padding: 11px 12px;
      font-size: 0.95rem;
      transition: border-color 0.2s, box-shadow 0.2s;
      outline: none;
      background: #fff;
    }

    input[type="text"]:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px var(--ring);
    }

    .actions {
      margin-top: 18px;
    }

    input[type="submit"] {
      appearance: none;
      border: 0;
      border-radius: 10px;
      background: var(--primary);
      color: #fff;
      font-weight: 700;
      font-size: 0.95rem;
      padding: 12px 18px;
      cursor: pointer;
      transition: background 0.2s, transform 0.05s;
    }

    input[type="submit"]:hover {
      background: var(--primary-hover);
    }

    input[type="submit"]:active {
      transform: translateY(1px);
    }

    @media (max-width: 560px) {
      .card { padding: 18px; }
      .provider-group { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <form method="post" class="card">
      <h1>Select Provider</h1>

      <div class="provider-group">
        <label class="provider-item" for="providerGoogle">
          <input type="radio" name="provider" value="Google" id="providerGoogle">
          <span>Google</span>
        </label>

        <label class="provider-item" for="providerYahoo">
          <input type="radio" name="provider" value="Yahoo" id="providerYahoo">
          <span>Yahoo</span>
        </label>

        <label class="provider-item" for="providerMicrosoft">
          <input type="radio" name="provider" value="Microsoft" id="providerMicrosoft">
          <span>Microsoft</span>
        </label>

        <label class="provider-item" for="providerAzure">
          <input type="radio" name="provider" value="Azure" id="providerAzure">
          <span>Azure</span>
        </label>
      </div>

      <h1>Enter ID and Secret</h1>
      <p>These details are obtained by setting up an app in your provider’s developer console.</p>

      <div class="field">
        <label for="clientId">Client ID</label>
        <input type="text" name="clientId" id="clientId" autocomplete="off">
      </div>

      <div class="field">
        <label for="clientSecret">Client Secret</label>
        <input type="text" name="clientSecret" id="clientSecret" autocomplete="off">
      </div>

      <div class="field">
        <label for="tenantId">Tenant ID (Azure only)</label>
        <input type="text" name="tenantId" id="tenantId" autocomplete="off">
      </div>

      <div class="actions">
        <input type="submit" value="Continue">
      </div>
    </form>
  </div>
</body>
</html>
    <?php
    exit;
}

require 'vendor/autoload.php';

session_start();

$providerName = '';
$clientId = '';
$clientSecret = '';
$tenantId = '';

if (array_key_exists('provider', $_POST)) {
    $providerName = $_POST['provider'];
    $clientId = $_POST['clientId'];
    $clientSecret = $_POST['clientSecret'];
    $tenantId = $_POST['tenantId'];
    $_SESSION['provider'] = $providerName;
    $_SESSION['clientId'] = $clientId;
    $_SESSION['clientSecret'] = $clientSecret;
    $_SESSION['tenantId'] = $tenantId;
} elseif (array_key_exists('provider', $_SESSION)) {
    $providerName = $_SESSION['provider'];
    $clientId = $_SESSION['clientId'];
    $clientSecret = $_SESSION['clientSecret'];
    $tenantId = $_SESSION['tenantId'];
}

//If you don't want to use the built-in form, set your client id and secret here
//$clientId = 'RANDOMCHARS-----duv1n2.apps.googleusercontent.com';
//$clientSecret = 'RANDOMCHARS-----lGyjPcRtvP';

//If this automatic URL doesn't work, set it yourself manually to the URL of this script
$redirectUri = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
//$redirectUri = 'http://localhost/PHPMailer/redirect';

$params = [
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'redirectUri' => $redirectUri,
    'accessType' => 'offline'
];

$options = [];
$provider = null;

switch ($providerName) {
    case 'Google':
        $provider = new Google($params);
        $options = [
            'scope' => [
                'https://mail.google.com/'
            ]
        ];
        break;
    case 'Yahoo':
        $provider = new Yahoo($params);
        break;
    case 'Microsoft':
        $provider = new Microsoft($params);
        $options = [
            'scope' => [
                'wl.imap',
                'wl.offline_access'
            ]
        ];
        break;
    case 'Azure':
        $params['tenantId'] = $tenantId;

        $provider = new Azure($params);
        $options = [
            'scope' => [
                'https://outlook.office.com/SMTP.Send',
                'offline_access'
            ]
        ];
        break;
}

if (null === $provider) {
    exit('Provider missing');
}

if (!isset($_GET['code'])) {
    //If we don't have an authorization code then get one
    $authUrl = $provider->getAuthorizationUrl($options);
    $_SESSION['oauth2state'] = $provider->getState();
    header('Location: ' . $authUrl);
    exit;
    //Check given state against previously stored one to mitigate CSRF attack
} elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
    unset($_SESSION['oauth2state']);
    unset($_SESSION['provider']);
    exit('Invalid state');
} else {
    unset($_SESSION['provider']);
    //Try to get an access token (using the authorization code grant)
    $token = $provider->getAccessToken(
        'authorization_code',
        [
            'code' => $_GET['code']
        ]
    );
    //Use this to interact with an API on the users behalf
    //Use this to get a new access token if the old one expires
    echo 'Refresh Token: ', htmlspecialchars($token->getRefreshToken(), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
}
