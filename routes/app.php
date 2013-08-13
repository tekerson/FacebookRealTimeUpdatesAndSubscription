<?php
$app->get('/', function () use ($app, $c) {
    $url = $c['facebook']->getLoginUrl(array(
        'scope' => 'email',
        'redirect_uri' => $app->request()->getUrl() . '/confirm',
    ));
 
    $app->view()->setData(array(
        'login_url' => $url,
    ));
 
    $app->render('index.html');
});

$app->get('/confirm', function () use ($app, $c) {
    $config = $c['config'];
    $facebook = $c['facebook'];
 
    // exchange the code for an access token
    $url = sprintf(
        'https://graph.facebook.com/oauth/access_token?client_id=%s&redirect_uri=%s&client_secret=%s&code=%s',
        $config['facebook.app_id'],
        urlencode($app->request()->getUrl() . '/confirm'),
        $config['facebook.app_secret'],
        $app->request()->get('code')
    );
    $response = file_get_contents($url);
 
    $params = null;
    parse_str($response, $params);
    $token = $params['access_token'];
 
    // exchange the access token for a long-term token
    $url = sprintf(
        'https://graph.facebook.com/oauth/access_token?grant_type=fb_exchange_token&client_id=%s&client_secret=%s&fb_exchange_token=%s',
        $config['facebook.app_id'],
        $config['facebook.app_secret'],
        $token
    );
    $response = file_get_contents($url);
 
    $params = null;
    parse_str($response, $params);
    $token = $params['access_token'];
    $tokenExpires = $params['expires'];
 
    // get the user's information
    $facebook->setAccessToken($token);
    $fb_user = $facebook->api('/me');
    $friends = $facebook->api('/me/friends');
 
    // create the database entry
    $c['db']->users->insert(array(
        'fb_id'   => $fb_user['id'],
        'name'    => $fb_user['name'],
        'email'   => $fb_user['email'],
        'friends' => serialize($friends['data']),
        'fb_access_token' => $token,
        'fb_access_token_expires' => $tokenExpires
    ));
});

$app->get('/subscriptions', function () use ($app, $c) {
    $req = $app->request();
    $verify = $c['config']['facebook.verify_token'];
    if ($req->get('hub_mode') == 'subscribe' && $req->get('hub_verify_token') == $verify) {
        echo $req->get('hub_challenge');
    }
});

$app->post('/subscriptions', function () use ($app, $c) {
    $req = $app->request();
    $headers = $req->headers();
     
    $signature = $headers['X_HUB_SIGNATURE'];
    $body = $req->getBody();
     
    $expected = 'sha1=' . hash_hmac('sha1', $body, $facebook->getApiSecret());
     
    if ($signature != $signature) {
        exit();
    }
    // We're okay to proceed

    $updates = json_decode($body, true);
    if ($updates['object'] == 'user') { 
        foreach ($updates['entry'] as $entry) {
            $uid = $entry['uid'];
            foreach ($entry['changed_fields'] as $field) {
                if ($field == 'friends') {
                    $user = $c['db']->users('fb_id = ?', $uid)->fetch();
                    if ($user) {
                        $data = unserialize($user['friends']);
                        $friendIDs = __::pluck($data, 'id');

                        $facebook->setAccessToken($user['fb_access_token']);
                        $response = $facebook->api('/me/friends');
                        $friendsData = $response['data'];
                         
                        $newFriendIDs = __::pluck($friendsData, 'id');
                        $removedIDs = array_diff($friendIDs, $newFriendIDs);
                         
                        if (count($removedIDs)) {
                            $html = '<p>The following people have un-friended you:</p>';
                            $html .= '<ul>';
                            foreach ($removedIDs as $id) {
                                $friend = $facebook->api($id);
                                $html .= '<ul>' . $friend['name'] . '</li>';
                            }
                            $html .= '</ul>';
                         
                            $mail = $c['phpmailer'];
                            $mail->AddAddress($user['email'], $user['name']);
                            $mail->Subject = 'Someone has un-friended you on Facebook!';
                            $mail->Body = $html;
                            $mail->Send();
                        }
                        $user->update(array(
                            'friends' => serialize($friendsData),
                        ));
                    }
                }
            }
        }
    }
});
