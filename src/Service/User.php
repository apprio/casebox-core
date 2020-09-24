<?php

namespace Casebox\CoreBundle\Service;

use Casebox\CoreBundle\Entity\UsersGroups as UsersGroupsEntity;
use Casebox\CoreBundle\Service\Auth\GoogleAuth;
use Casebox\CoreBundle\Service\Auth\YubikeyAuth;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Colors\RandomColor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\DependencyInjection\Container;

/**
 * Class User
 */
class User
{
    use TranslatorTrait;

    public function __construct(Container $container = null)
    {
        if (empty($container)) {
            $container = Cache::get('symfony.container');
        }
        $this->container = $container;
        $this->configService = $container->get('casebox_core.service.config');
    }

    /**
     * Password verification method used for accessing sensitive data (like profile form)
     * or for additional identity check
     *
     * @param string $password
     *
     * @return array response
     */
    public static function verifyPassword($password)
    {
        $result = ['success' => false];

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        $session->remove('verified');

        $user = self::isLogged();

        if (strlen($user->getPassword()) <= 32) {
            // Old password behavior
            $encodedPass = md5('aero'.$password);
        } else {
            $encoder = Cache::get('symfony.container')->get('casebox_core.service_auth.authentication')
                ->getEncoderFactoryInterface()
                ->getEncoder($user);

            $encodedPass = $encoder->encodePassword($password, $user->getSalt());
        }

        if ($encodedPass !== $user->getPassword()) {
            return $result['msg'] = self::trans('Auth_fail');
        }

        $result['success'] = true;
        $session->set('verified', time());

        return $result;
    }

    /**
     * Phone verification method. Send an sms message and prompt to insert received code
     *
     * @param string $p
     *
     * @return array
     */
    public function verifyPhone($p)
    {
        $result = ['success' => true];

        // $phone = preg_replace('/[^0-9]+/', '', $p['country_code'] . $p['phone_number']);
        return $result;
    }

    /**
     * Enable Two Step Verification mechanism
     *
     * @param object $p
     *
     * @return array response
     */
    public function enableTSV($p)
    {
        //if (!$this->isVerified()) {
        //    return ['success' => false, 'verify' => true];
        //}

        // validate TSV mechanism
        if (!in_array($p['method'], ['ga', 'sms', 'ybk'])) {
            return ['success' => false, 'msg' => 'Invalid authentication mechanism'];
        }
        $data = empty($p['data']) ? [] : (array)$p['data'];

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        $lastTSV = $session->get('lastTSV');

        if ($session->has('lastTSV') && !empty($lastTSV[$p['method']])) {
            $data = array_merge($lastTSV[$p['method']], $data);
        }

        $result = ['success' => true];

        $authenticator = $this->getTSVAuthenticator($p['method']);
        $data = $authenticator->createSecretData($data);
        $authenticator->setSecretData($data);

        if ($p['method'] == 'ybk') { //cant verify right after client creation, should pass some time
            $this->setTSVConfig(
                [
                    'method' => $p['method'],
                    'sd' => $data,
                ]
            );
        } elseif ($authenticator->verifyCode($data['code'])) {
            $cfg = [
                'method' => $p['method'],
                'sd' => $data,
            ];
            $this->setTSVConfig($cfg);

            $session->remove('lastTSV');
        } else {
            $result['success'] = false;
        }

        return $result;
    }

    public static function disableTSV($userId = false)
    {
        if (!static::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        static::setTSVConfig(null, $userId);

        return ['success' => true];
    }

    /**
     * Check if user is logged in current session
     * @return UsersGroupsEntity
     */
    public static function isLogged()
    {
        return Cache::get('symfony.container')->get('casebox_core.service_auth.authentication')->isLogged();
    }

    /**
     * Check if user did a password verification check in specified period of time.
     * Default is 5 minutes
     * Changed to 1 hour.
     */
    public static function isVerified()
    {
        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');

        return (!empty($session->get('verified')));
    }

    /**
     * @return array|null
     */
    public function getUserData()
    {
        $auth = Cache::get('symfony.container')->get('casebox_core.service_auth.authentication');
        $user = $auth->isLogged(true);

        $data = $auth->getSessionData($user->getId());

        return $data;
    }

    /**
     * Get login info for current logged user
     *
     * @return array json response
     */
    public function getLoginInfo()
    {
        $coreName = $this->configService->get('coreName');

        $filesConfig = $this->configService->get('files');

        if (empty($filesConfig['edit']['webdav'])) {
            $webdavFiles = $this->configService->get('webdav_files');
        } else {
            $webdavFiles = $filesConfig['edit']['webdav'];
        }

        $filesEdit = empty($filesConfig['edit']) ? [] : $filesConfig['edit'];
        $filesEdit['webdav'] = $webdavFiles;

        // transform element values in array of file extensions
        foreach ($filesEdit as $k => $v) {
            $filesEdit[$k] = Util\toTrimmedArray($v);
        }

        @$result = [
            'success' => true,
            'config' => [
                'coreName' => $coreName,
                'photoPath' => '/c/'.$coreName.'/photo/',
                'rtl' => $this->configService->get('rtl'),
                'folder_templates' => $this->configService->get('folder_templates'),
                'default_task_template' => $this->configService->get('default_task_template'),
                'default_event_template' => $this->configService->get('default_event_template'),
                'files.edit' => $filesEdit,
                'default_object_edit_mode' => $this->configService->get('default_object_edit_mode', 'view'),
                'template_info_column' => $this->configService->get('template_info_column'),
                'leftRibbonButtons' => $this->configService->get('leftRibbonButtons'),
            ],
            'user' => $this->getUserData(),
        ];

        $result['config']['files.edit'] = $filesEdit;

        // default root node config
        $root = $this->configService->get('rootNode');
        if (is_null($root)) {
            $root = Browser::getRootProperties(Browser::getRootFolderId())['data'];

        } else {
            $root = Util\toJSONArray($root);
            if (isset($root['id'])) {
                $root['nid'] = $root['id'];
                unset($root['id']);
            }
        }
        $result['config']['rootNode'] = $root;

        unset($result['user']['TSV_checked']);

        return $result;
    }

    /**
     * Get account data for profile and security forms
     */
    public function getAccountData()
    {
        if (!$this->isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        // update verification time
        $session->set('verified', time());

        return [
            'success' => true,
            'profile' => $this->getProfileData(),
            'security' => $this->getSecurityData(),
        ];
    }

    /**
     * Get profile data for a user.
     * This function receives userId as param because user profile data can be edited by another user (owner).
     */
    public function getProfileData($userId = false)
    {
        if (!$this->isVerified() && $userId) {
            return ['success' => false, 'verify' => true];
        }

        if ($userId === false) {
            $userId = static::getId();
        }
        if (!Security::canEditUser($userId)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $result = [];

        $r = $this->getPreferences($userId);
        if (!empty($r)) {
            $cfg = $r['cfg'];
            unset($r['cfg']);

            $languageIndex = 0;
            if (!empty($r['language_id'])) {
                $languageIndex = $r['language_id'] - 1;
            }

            $r['language'] = $this->configService->get('languages')[$languageIndex];

            if (empty($cfg['long_date_format'])) {
                $language = Cache::get('symfony.container')
                    ->get('casebox_core.service_vocabulary.language_vocabulary')
                    ->findByLanguage($r['language']);
                $r['long_date_format'] = $language['long_date_format'];
            } else {
                $r['long_date_format'] = $cfg['long_date_format'];
            }

            if (empty($cfg['short_date_format'])) {
                $language = Cache::get('symfony.container')
                    ->get('casebox_core.service_vocabulary.language_vocabulary')
                    ->findByLanguage($r['language']);
                $r['short_date_format'] = $language['short_date_format'];
            } else {
                $r['short_date_format'] = $cfg['short_date_format'];
            }

            if (!empty($cfg['country_code'])) {
                $r['country_code'] = $cfg['country_code'];
            }
            if (!empty($cfg['phone'])) {
                $r['phone'] = $cfg['phone'];
            }
            if (!empty($cfg['timezone'])) {
                $r['timezone'] = $cfg['timezone'];
            }

            if (!empty($cfg['canAddUsers'])) {
                $r['canAddUsers'] = $cfg['canAddUsers'];
            }
            if (!empty($cfg['canAddGroups'])) {
                $r['canAddGroups'] = $cfg['canAddGroups'];
            }
            $r['template_id'] = User::getTemplateId();

            $result = $r;
        }

        // get possible associated objects for display in grid
        if (!empty($result['data'])) {
            $assocObjects = Objects::getAssociatedObjects(
                [
                    'template_id' => $result['template_id'],
                    'data' => $result['data'],
                ]
            );
            if (!empty($assocObjects['data'])) {
                $result['assocObjects'] = $assocObjects['data'];
            }
        }

        $result['success'] = true;

        return $result;
    }

    private function getSecurityData()
    {
        $rez = [];

        $r = DM\Users::read(User::getId());

        if (!empty($r)) {
            $cfg = Util\toJSONArray($r['cfg']);
            if (!empty($cfg['security'])) {
                $rez = $cfg['security'];
            }
            $rez['password_change'] = $r['password_change'];
            if (empty($rez['phone']) && !empty($cfg['phone'])) {
                $rez['phone'] = $cfg['phone'];
            }
        }

        return $rez;
    }

    /**
     * Save user profile form data
     *
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function saveProfileData($p)
    {
        if (!$this->isVerified() && ($p['id'] != static::getId())) {
            return ['success' => false, 'verify' => true];
        }

        if (!Security::canEditUser($p['id'])) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $cfg = $this->getUserConfig($p['id']);

        $p['first_name'] = Purify::humanName($p['first_name']);
        $p['last_name'] = Purify::humanName($p['last_name']);

        $p['sex'] = (strlen($p['sex']) > 1) ? null : $p['sex'];

        if (isset($p['data']['user_role']['value'])) { 
          if ($p['data']['user_role']['value'] == 248274) {
            if ($p['data']['user_role']['childs']['assignedworker']) {
              // If assignedworkers are selected, assigned logged user's id in the assignedworker's data as their assignedsupervisor
              $workerId = explode(',' , $p['data']['user_role']['childs']['assignedworker']);
              foreach ($workerId as $worker) {
                $params = [
                    'id' => $worker,
                    'data' => Util\jsonEncode(['assignedsupervisor' => $p['id']])
                ];
                DM\Users::update($params);
              }
            }
          }
        }

        if (!empty($p['email'])) {
            if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'msg' => 'Invalid email address'];
            }
        }

        $p['language_id'] = intval($p['language_id']);

        if (isset($p['country_code'])) {
            if (empty($p['country_code']) ||
                filter_var(
                    $p['country_code'],
                    FILTER_VALIDATE_REGEXP,
                    [
                        'options' => [
                            'regexp' => '/^\+?\d*$/',
                        ],
                    ]
                )
            ) {
                $cfg['country_code'] = $p['country_code'];
            } else {
                return ['success' => false, 'msg' => 'Invalid country code'];
            }
        }

        if (isset($p['phone']) && !empty($p['phone'])) {
            // remove all symbols except 0-9, (, ), -, +
            $phone = preg_replace("/[^0-9 \-\(\)\+]/", '', $p['phone']);
            $cfg['phone'] = $phone;
        }

        if (isset($p['timezone'])) {
            # list of (all) valid timezones
            $zoneList = timezone_identifiers_list();

            if (empty($p['timezone']) || in_array($p['timezone'], $zoneList)) {
                $cfg['timezone'] = $p['timezone'];
            } else {
                return ['success' => false, 'msg' => 'Invalid timezone'];
            }
        }

        if (isset($p['short_date_format'])) {
            if (filter_var(
                $p['short_date_format'],
                FILTER_VALIDATE_REGEXP,
                [
                    'options' => [
                        'regexp' => '/^[\.,a-z \/\-]*$/i',
                    ],
                ]
            )) {
                $cfg['short_date_format'] = $p['short_date_format'];
            } else {
                return ['success' => false, 'msg' => 'Invalid short date format'];
            }
        }

        if (isset($p['long_date_format'])) {
            if (filter_var(
                $p['long_date_format'],
                FILTER_VALIDATE_REGEXP,
                [
                    'options' => [
                        'regexp' => '/^[\.,a-z \/\-]*$/i',
                    ],
                ]
            )) {
                $cfg['long_date_format'] = $p['long_date_format'];
            } else {
                return [
                    'success' => false,
                    'msg' => 'Invalid long date format',
                ];
            }
        }

        if (empty($p['data'])) {
            $p['data'] = [];
        }

        if ($p['id'] != static::getId()) {
            if (Security::canAddUser()) {
                unset($cfg['canAddUsers']);
                if (isset($p['canAddUsers'])) {
                    $cfg['canAddUsers'] = 'true';
                }
            }
            if (Security::canAddGroup()) {
                unset($cfg['canAddGroups']);
                if (isset($p['canAddGroups'])) {
                    $cfg['canAddGroups'] = 'true';
                }
            }
        }

        $params = [
            'id' => $p['id'],
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name'],
            'sex' => $p['sex'],
            'email' => $p['email'],
            'language_id' => $p['language_id'],
            'cfg' => Util\jsonEncode($cfg),
            'data' => Util\jsonEncode($p['data']),
        ];

        DM\Users::update($params);

        // Updating session params if the updated user profile is currently logged user
        if ($p['id'] == static::getId()) {
            /** @var Session $session */
            $session = Cache::get('symfony.container')->get('session');
            $u = $session->get('user');

            $u['first_name'] = htmlentities($p['first_name'], ENT_QUOTES, 'UTF-8');
            $u['last_name'] = htmlentities($p['last_name'], ENT_QUOTES, 'UTF-8');

            $u['sex'] = $p['sex'];
            $u['email'] = $p['email'];
            $u['language_id'] = $p['language_id'];

            $u['language'] = @$this->configService->get('languages')[$p['language_id'] - 1];

            $language = Cache::get('symfony.container')
                ->get('casebox_core.service_vocabulary.language_vocabulary')
                ->findByLanguage($u['language']);

            $u['locale'] = (!empty($language['locale'])) ? $language['locale'] : '';

            $u['cfg']['timezone'] = empty($cfg['timezone']) ? '' : $cfg['timezone'];
            $u['cfg']['gmt_offset'] = empty($cfg['timezone']) ? null : System::getGmtOffset($cfg['timezone']);

            if (!empty($cfg['long_date_format'])) {
                $u['cfg']['long_date_format'] = $cfg['long_date_format'];
            }
            if (!empty($cfg['short_date_format'])) {
                $u['cfg']['short_date_format'] = $cfg['short_date_format'];
            }

            $u['cfg']['time_format'] = (!empty($language['time_format'])) ? $language['time_format'] : '';

            $session->set('user', $u);
        }

        return ['success' => true];
    }

    public function saveSecurityData($p)
    {
        if (!$this->isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        // update verification time
        $session->set('verified', time());

        $cfg = $this->getUserConfig();

        if (empty($cfg['security'])) {
            $cfg['security'] = [];
        }
        if (empty($p['recovery_mobile'])) {
            unset($cfg['security']['recovery_mobile']);
        } else {
            $cfg['security']['recovery_mobile'] = true;
        }
        if (empty($p['country_code'])) {
            unset($cfg['security']['country_code']);
        } else {
            $cfg['security']['country_code'] = $p['country_code'];
        }
        if (empty($p['phone_number'])) {
            unset($cfg['security']['phone_number']);
        } else {
            $cfg['security']['phone_number'] = $p['phone_number'];
        }

        if (empty($p['recovery_email'])) {
            unset($cfg['security']['recovery_email']);
        } else {
            $cfg['security']['recovery_email'] = true;
        }
        if (empty($p['email'])) {
            unset($cfg['security']['email']);
        } else {
            $cfg['security']['email'] = $p['email'];
        }

        if (empty($p['recovery_question'])) {
            unset($cfg['security']['recovery_question']);
        } else {
            $cfg['security']['recovery_question'] = true;
        }
        if (empty($p['question_idx'])) {
            unset($cfg['security']['question_idx']);
        } else {
            $cfg['security']['question_idx'] = $p['question_idx'];
        }
        if (empty($p['answer'])) {
            unset($cfg['security']['answer']);
        } else {
            $cfg['security']['answer'] = $p['answer'];
        }

        $this->setUserConfig($cfg);

        return ['success' => true];
    }

    /**
     * Get secret data
     *
     * @param string $p authentication mechanism abreviation ('ga', 'sms', 'ybk')
     *
     * @return array response
     */
    public function getTSVTemplateData($p)
    {
        //Commented out by DS to generate the key beforehand
        //if (!$this->isVerified()) {
        //    return ['success' => false, 'verify' => true];
        //}

        // Validate TSV mechanism
        if (!in_array($p, ['ga', 'sms', 'ybk'])) {
            return ['success' => false, 'msg' => 'Invalid authentication mechanism'];
        }

        $rez = [
            'success' => true,
            'data' => null,
        ];

        $cfg = $this->getTSVConfig();

        $authenticator = $this->getTSVAuthenticator($p);

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        $lastTSV = ($session->has('lastTSV')) ? $session->get('lastTSV') : [];

        if (empty($cfg['method']) || empty($cfg['sd']) || ($cfg['method'] != $p)) {
            $lastTSV[$p] = $authenticator->prepareSecretDataCreation();
        } else {
            $lastTSV[$p] = $cfg['sd'];
        }

        $authenticator->setSecretData($lastTSV[$p]);

        $rez['data'] = $authenticator->getTemplateData();

        $session->set('lastTSV', $lastTSV);

        return $rez;
    }

    /**
     * Verify given Google Authenticator code
     *
     * @param string $code
     *
     * @return bool
     */
    public function verifyGACode($code)
    {
        $sk = $this->getGASk();
        $sk = $sk['sk'];
        $ga = new GoogleAuth();

        return $ga->verifyCode($sk, $code);
    }

    /**
     * Logout current logged in user
     *
     * @return array
     */
    public function logout()
    {
        $result = ['success' => true];

        try {
            Cache::get('symfony.container')->get('casebox_core.service_auth.authentication')->logout();
        } catch (\Exception $e) {
            $result = ['success' => false, 'msg' => $e->getTraceAsString()];
        }

        return $result;
    }

    /**
     * Change language for currently logged user
     *
     * @param int $id Language id
     *
     * @return array
     */
    public function setLanguage($id)
    {
        $coreUILanguages = $this->configService->get('languagesUI');

        if (isset($coreUILanguages[$id - 1])) {
            /** @var Session $session */
            $session = Cache::get('symfony.container')->get('session');
            $user = $session->get('user');

            $user['language_id'] = $id;
            $user['language'] = $coreUILanguages[$id - 1];

            $session->set('user', $user);

            setcookie('L', $coreUILanguages[$id - 1]);
        } else {
            return ['success' => false];
        }

        $params = [
            'id' => User::getId(),
            'language_id' => $id,
        ];

        DM\Users::update($params);

        return ['success' => true];
    }

    /**
     * Change theme for currently logged user
     *
     * @param int $id Language id
     *
     * @return array
     */
    public function setTheme($id)
    {
        $id = Purify::filename($id);

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        $user = $session->get('user');
        $user['cfg']['theme'] = $id;

        $session->set('user', $user);

        $cfg = $this->getUserConfig();
        $cfg['theme'] = $id;
        $this->setUserConfig($cfg);

        return ['success' => true];
    }

    /**
     * Get the maximum rows displayed in grid
     * @return int
     */
    public function getGridMaxRows()
    {
        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        $user = $session->get('user');

        if (!empty($user['cfg']['max_rows'])) {
            return $user['cfg']['max_rows'];
        }

        return $this->configService->get('max_rows');
    }

    /**
     * Set the maximum rows displayed in grid
     *
     * @param int $rows
     *
     * @return boolean
     */
    public static function setGridMaxRows($rows)
    {
        if (!is_numeric($rows)) {
            return false;
        }

        if ($rows < 25) {
            $rows = 25;
        } elseif ($rows > 200) {
            $rows = 200;
        }

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');
        $user = $session->get('user');
        $user['cfg']['max_rows'] = $rows;

        $session->set('user', $user);

        $cfg = static::getUserConfig();
        $cfg['max_rows'] = $rows;
        static::setUserConfig($cfg);

        return true;
    }

    /**
     * Upload user photo
     *
     * @param array $p upload params using form post
     *
     * @return array response
     */
    public function uploadPhoto($p)
    {
        if (!$this->isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!is_numeric($p['id'])) {
            return ['success' => false, 'msg' => $this->trans('Wrong_id')];
        }
        $f = &$_FILES['photo'];
        if (!in_array($f['error'], [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE])) {
            return ['success' => false, 'msg' => $this->trans('Error_uploading_file').': '.$f['error']];
        }

        if (substr($f['type'], 0, 6) !== 'image/') {
            return ['success' => false, 'msg' => 'Not an image'];
        }

        $photoName = $p['id'].'_'.preg_replace('/[^a-z0-9\.]/i', '_', $f['name']).'.png';

        $photosPath = $this->configService->get('photos_path');

        $fs = new Filesystem();
        if (!$fs->exists($photosPath)) {
            $fs->mkdir($photosPath);
        }

        try {
            $image = new \Imagick($f['tmp_name']);
            $image->resizeImage(100, 100, \imagick::FILTER_LANCZOS, 0.9, true);
            $image->setImageFormat('png');
            $image->writeImage($photosPath.$photoName);

            //create also a 32x32 photo file to embed in emails and other places
            $image->resizeImage(32, 32, \imagick::FILTER_LANCZOS, 0.9, true);
            $image->writeImage($photosPath.'32x32_'.$photoName);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'msg' => 'This image format is not supported, please upload a PNG, JPG image.',
            ];
        }

        $params = [
            'id' => $p['id'],
            'photo' => $photoName,
        ];

        DM\Users::update($params);

        return ['success' => true, 'photo' => $photoName];
    }

    /**
     * Remove users photo
     *
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function removePhoto($p)
    {
        if (!$this->isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!is_numeric($p['id'])) {
            return ['success' => false, 'msg' => $this->trans('Wrong_id')];
        }

        if (!Security::canEditUser($p['id'])) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $r = DM\Users::read($p['id']);

        if (!empty($r['photo'])) {
            @unlink($this->configService->get('photos_path').$r['photo']);
        }

        // update db record
        $params = [
            'id' => $p['id'],
            'photo' => null,
        ];

        DM\Users::update($params);

        return ['success' => true];
    }

    /**
     * Get id of currently logged user
     * @return int | null
     */
    public static function getId()
    {
        $rez = null;

        $user = Cache::get('session')->get('user');
        if (!empty($user['id'])) {
            $rez = intval($user['id']);
        }

        return $rez;
    }

    /**
     * Generate recovery hash for a given user
     *
     * @param int $userId
     * @param string $random
     *
     * @return string
     */
    public static function generateRecoveryHash($userId, $random)
    {
        $hash = password_hash($random, PASSWORD_BCRYPT, ['cost' => 15]);

        $params = [
            'id' => $userId,
            'recover_hash' => $hash,
        ];

        DM\Users::update($params);

        return $hash;
    }

    /**
     * Set new password for a user by his recovery hash
     *
     * @param  string $hash
     * @param  string $password
     *
     * @return bool
     */
    public static function setNewPasswordByRecoveryHash($hash, $password)
    {
        $rez = false;

        $id = DM\Users::getIdByRecoveryHash($hash);

        if (!empty($id)) {
            $params = [
                'id' => $id,
                'password' => $password,
                'recover_hash' => null,
            ];

            DM\Users::update($params);

            $rez = true;
        }

        return $rez;
    }

    /**
     * Check if a given user is public
     *
     * @param int|bool|false $userId
     *
     * @return boolean
     */
    public static function isPublic($userId = false)
    {
        $rez = false;

        $config = static::getUserConfig($userId);
        if (!empty($config['public_access'])) {
            $rez = true;
        }

        return $rez;
    }

    /**
     * @return string|null
     */
    public static function getTemplateId()
    {
        $rez = null;

        $r = DM\Templates::getIdsByType('user');
        $rez = array_shift($r);

        return $rez;
    }

    /**
     * @param string $authMechanism
     * @param array|null $data
     *
     * @return string
     */
    public function getTSVAuthenticator($authMechanism, $data = null)
    {
        if (!isset($this->authClasses[$authMechanism])) {
            switch ($authMechanism) {
                case 'ga':
                case 'sms':
                    $this->authClasses[$authMechanism] = new GoogleAuth(null, $data);
                    break;
                case 'ybk':
                    $this->authClasses[$authMechanism] = new YubikeyAuth($data);
                    break;
            }
        }

        return $this->authClasses[$authMechanism];
    }

    /**
     * @param integer|array|bool $idOrData
     * @param string|bool $withEmail
     *
     * @return array|null|\PDO|string
     */
    public static function getDisplayName($idOrData = false, $withEmail = false)
    {
        $data = [];

        if ($idOrData === false) { //use current logged users
            $id = static::getId();
        } elseif (is_numeric($idOrData)) { //id specified
            $id = $idOrData;
        } elseif (is_array($idOrData) && !empty($idOrData['id']) && is_numeric($idOrData['id'])) {
            $id = $idOrData['id'];
            $data = $idOrData;
        } else {
            return '';
        }

        $var_name = 'users['.$id."]['displayName$withEmail']";

        if (!Cache::exist($var_name)) {
            if (empty($data)) {
                $data = DM\Users::read($id);
            }

            $name = @Purify::humanName($data['first_name'].' '.$data['last_name']);

            if (empty($name)) {
                $name = @$data['name'];
            }

            if (($withEmail == true) && (!empty($r['email']))) {
                $name .= "\n(".$r['email'].")";
            }

            $name = htmlentities($name, ENT_QUOTES, 'UTF-8');

            Cache::set($var_name, $name);
        }

        return Cache::get($var_name);
    }

    /**
     * Get username
     *
     * @param array|bool $idOrData
     *
     * @return string
     */
    public static function getUsername($idOrData = false)
    {
        if ($idOrData === false) {
            /** @var Session $session */
            $session = Cache::get('symfony.container')->get('session');

            $idOrData = $session->get('user');
        }

        $data = is_numeric($idOrData) ? static::getPreferences($idOrData) : $idOrData;

        $rez = empty($data['name']) ? '' : $data['name'];

        return $rez;
    }

    /**
     * Get user email
     *
     * @param array|bool $idOrData
     *
     * @return string
     */
    public static function getEmail($idOrData = false)
    {
        if ($idOrData === false) {
            $idOrData = static::getId();
        }

        $data = is_numeric($idOrData) ? static::getPreferences($idOrData) : $idOrData;

        $rez = empty($data['email']) ? '' : $data['email'];

        if (!empty($data['cfg']['security'])) {
            $sec = &$data['cfg']['security'];

            if (!empty($sec['recovery_email'])) {
                $rez = $sec['recovery_email'];
            }

            // check if mail is set in security settings
            if (!empty($sec['recovery_email']) && !empty($sec['email'])) {
                $rez = $sec['email'];
            }
        }

        return $rez;
    }

    /**
     * Get a user photo if set
     *
     * @param integer|array|bool|false $idOrData Id or user data array
     * @param string|bool $size32
     *
     * @return string
     */
    public function getPhotoFilename($idOrData = false, $size32 = false)
    {
        $data = [];

        if ($idOrData === false) { //use current logged users
            $id = static::getId();

        } elseif (is_numeric($idOrData)) { //id specified
            $id = $idOrData;

        } elseif (is_array($idOrData) && !empty($idOrData['id']) && is_numeric($idOrData['id'])) {
            $id = $idOrData['id'];
            $data = $idOrData;

        } else {
            return '';
        }

        $docRoot = Cache::get('platformConfig')['cb_doc_root'];

        $var_name = 'users['.$id."]['photoFilename$size32']";

        if (!Cache::exist($var_name)) {
            if (empty($data)) {
                $data = DM\Users::read($id);
            }

            // set result to default placeholder
            $rez = $docRoot.'/css/i/ico/32/user-male.png';

            $photosPath = $this->configService->get('photos_path');
            $photoFile = $photosPath.@$data['photo'];

            if (file_exists($photoFile) && !is_dir($photoFile)) {
                if ($size32) {
                    $photoFile32 = $photosPath.'32x32_'.@$data['photo'];

                    // create thumb photo if not exists
                    if (!file_exists($photoFile32)) {
                        try {
                            $image = new \Imagick($photoFile);
                            $image->resizeImage(32, 32, \imagick::FILTER_LANCZOS, 0.9, true);
                            $image->writeImage($photoFile32);
                            $rez = $photoFile32;
                        } catch (\Exception $e) {

                        }
                    } else {
                        $rez = $photoFile32;
                    }
                } else {
                    $rez = $photoFile;
                }
            } elseif (@$data['sex'] == 'f') {
                $rez = $docRoot.'/css/i/ico/32/user-female.png';
            }

            Cache::set($var_name, $rez);
        }

        return Cache::get($var_name);
    }

    /**
     * Get photo param to be added for photo urls
     *
     * @param integer|array|bool|false $idOrData
     *
     * @return string
     */
    public function getPhotoParam($idOrData = false)
    {
        $data = [];

        if ($idOrData === false) { // use current logged users
            $id = static::getId();

        } elseif (is_numeric($idOrData)) { // id specified
            $id = $idOrData;

        } elseif (is_array($idOrData) && !empty($idOrData['id']) && is_numeric($idOrData['id'])) {
            $id = $idOrData['id'];
            $data = $idOrData;

        } else {
            return '';
        }

        $var_name = 'users['.$id."]['photoParam']";

        if (!Cache::exist($var_name)) {
            if (empty($data)) {
                $data = DM\Users::read($id);
            }

            $rez = '';

            $photosPath = $this->configService->get('photos_path');
            $photoFile = $photosPath.$data['photo'];

            if (file_exists($photoFile)) {
                $rez = date('ynjGis', filemtime($photoFile));
            }

            Cache::set($var_name, $rez);
        }

        return Cache::get($var_name);
    }

    /**
     * Get user preferences
     *
     * @param  integer $userId
     *
     * @return array|null
     */
    public static function getPreferences($userId)
    {
        $rez = [];
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        $coreLanguages = $configService->get('languages');

        $data = DM\Users::read($userId);

        $params = [
            'id' => 1,
            'name' => 1,
            'first_name' => 1,
            'last_name' => 1,
            'sex' => 1,
            'email' => 1,
            'language_id' => 1,
            'cfg' => 1,
            'data' => 1,
        ];

        $r = array_intersect_key($data, $params);

        if (!empty($r)) {
            $languageIndex = 0;
            if (!empty($r['language_id'])) {
                $languageIndex = $r['language_id'] - 1;
            }

            $ls = Cache::get('symfony.container')
                ->get('casebox_core.service_vocabulary.language_vocabulary')
                ->findByLanguage($coreLanguages[$languageIndex]);

            $r['language'] = $coreLanguages[$languageIndex];
            $r['locale'] = $ls['locale'];

            $r['cfg'] = Util\toJSONArray($r['cfg']);

            if (empty($r['cfg']['long_date_format'])) {
                $r['cfg']['long_date_format'] = $ls['long_date_format'];
            }

            if (empty($r['cfg']['short_date_format'])) {
                $r['cfg']['short_date_format'] = $ls['short_date_format'];
            }

            $r['cfg']['time_format'] = $ls['time_format'];

            // Date formats are sotred in Php format (not mysql)
            // for backward compatibility we remove all % chars
            $r['cfg']['long_date_format'] = str_replace('%', '', $r['cfg']['long_date_format']);
            $r['cfg']['short_date_format'] = str_replace('%', '', $r['cfg']['short_date_format']);
            $r['cfg']['time_format'] = str_replace('%', '', $r['cfg']['time_format']);

            // check for backward compatibility
            if (!empty($r['cfg']['TZ'])) {
                $r['cfg']['timezone'] = $r['cfg']['TZ'];
                unset($r['cfg']['TZ']);
            }

            if (!empty($r['cfg']['timezone'])) {
                $r['cfg']['gmt_offset'] = System::getGmtOffset($r['cfg']['timezone']);
            }

            $r['data'] = Util\toJSONArray($r['data']);

            $rez = $r;
        }

        return $rez;
    }

    /**
     * Get timezone for a given user id
     *
     * @param int|bool|false $userId
     *
     * @return string
     */
    public static function getTimezone($userId = false)
    {
        $rez = 'UTC';

        /** @var Session $session */
        $session = Cache::get('symfony.container')->get('session');

        $pref = ($session->has('user')) ? $session->get('user') : null;

        if ($userId !== false) {
            $pref = static::getPreferences($userId);
        }

        if (!empty($pref['cfg']['timezone']) && System::isValidTimezone($pref['cfg']['timezone'])) {
            $rez = $pref['cfg']['timezone'];
        }

        return $rez;
    }

    /**
     * @param integer|bool $userId
     *
     * @return array
     */
    private static function getUserConfig($userId = false)
    {
        if ($userId === false) {
            $userId = static::getId();
        }

        $r = DM\Users::read($userId);
        $cfg = [];

        if (!empty($r['cfg'])) {
            $cfg = Util\toJSONArray($r['cfg']);
        }

        return $cfg;
    }

    /**
     * @param array $cfg
     * @param integer|bool $userId
     */
    private static function setUserConfig($cfg, $userId = false)
    {
        if ($userId === false) {
            $userId = User::getId();
        }

        $params = [
            'id' => $userId,
            'cfg' => Util\jsonEncode($cfg),
        ];

        DM\Users::update($params);
    }

    /**
     * @param string $name
     * @param string|null $default
     * @param bool|string $userId
     *
     * @return null
     */
    public static function getUserConfigParam($name, $default = null, $userId = false)
    {
        $cfg = static::getUserConfig($userId);

        return empty($cfg[$name]) ? $default : $cfg[$name];
    }

    /**
     * Set users config param
     *
     * @param string $name
     * @param string|int|bool $value
     * @param string|bool $userId
     */
    public static function setUserConfigParam($name, $value, $userId = false)
    {
        $cfg = static::getUserConfig($userId);

        $cfg[$name] = $value;

        static::setUserConfig($cfg, $userId);
    }

    /**
     * @param integer|bool $userId
     *
     * @return array
     */
    public static function getTSVConfig($userId = false)
    {
        $rez = [];
        $cfg = static::getUserConfig($userId);
        if (!empty($cfg['security']['TSV'])) {
            $rez = $cfg['security']['TSV'];
        }

        return $rez;
    }

    /**
     * @param array|string $TSVConfig
     * @param integer|bool $userId
     */
    private static function setTSVConfig($TSVConfig, $userId = false)
    {
        $cfg = static::getUserConfig($userId);
        $cfg['security']['TSV'] = $TSVConfig;
        static::setUserConfig($cfg, $userId);
    }

    /**
     * @param integer|bool $userId
     *
     * @return array
     */
    public static function getNotificationSettings($userId = false)
    {
        $rez = [
            'success' => true,
            'data' => [
                'notifyFor' => 'mentioned',
                'delay' => 2,
                'delaySize' => 15,
            ],
        ];

        $ns = static::getUserConfigParam('notificationSettings', false, $userId);

        if (!empty($ns)) {
            $rez['data'] = $ns;
        }

        return $rez;
    }

    /**
     * @param array $p
     * @param integer|bool $userId
     *
     * @return array
     */
    public static function setNotificationSettings($p, $userId = false)
    {
        $d = [
            'notifyFor' => empty($p['notifyFor']) ? 'mentioned' : $p['notifyFor'],
            'delay' => empty($p['delay']) ? 2 : intval($p['delay']),
            'delaySize' => empty($p['delaySize']) ? 15 : intval($p['delaySize']),
        ];

        static::setUserConfigParam('notificationSettings', $d, $userId);

        return ['success' => true];
    }

    /**
     * @param integer|bool $userId
     *
     * @return array|null
     */
    public static function getLastActionTime($userId = false)
    {
        $rez = null;

        if (!is_numeric($userId)) {
            $userId = static::getId();
        }

        $r = DM\Users::read($userId);

        if (!empty($r['last_action_time'])) {
            $rez = $r['last_action_time'];
        }

        return $rez;
    }

    /**
     * @return bool
     */
    public static function updateLastActionTime()
    {
        $params = [
            'id' => static::getId(),
            'last_action_time' => Util\dateISOToMysql('now'),
        ];

        return DM\Users::update($params);
    }

    /**
     * @param integer|bool $userId
     *
     * @return array|bool
     */
    public static function isIdle($userId = false)
    {
        $rez = true;

        $lat = static::getLastActionTime($userId);

        if (!empty($lat)) {
            $minutes = Util\getDatesDiff($lat);
            $rez = ($minutes > 2);
        }

        return $rez;
    }

    /**
     * Check if reached specified interval for sending emails
     *
     * @param  integer|bool $userId
     *
     * @return string|bool
     *         false - if user not idle
     *         all - if user selected to receive all types of notifications
     *                 and specified timeout have passed from lastNotifyTime
     *         mentioned - if user selected to receive only mentioned or assigned notifications;
     *                 mentioned notifications are sent instantly
     *                 because are considered important actions
     */
    public static function canSendNotifications($userId = false)
    {
        $rez = static::isIdle($userId);

        if ($rez) {
            $rez = false;
            $s = static::getNotificationSettings($userId)['data'];

            switch ($s['notifyFor']) {
                case 'all':
                case 'mentioned':
                    $rez = $s['notifyFor'];

                    if ($s['delay'] == 2) {
                        $lastNotifyTime = static::getUserConfigParam('lastNotifyTime', false, $userId);

                        if (!empty($lastNotifyTime)) {
                            $diff = Util\getDatesDiff($lastNotifyTime);
                            if ($diff < $s['delaySize']) {
                                // send mentioned notifications instantly if idle
                                // no matter of delay, only other nitifications will be merged after delay
                                $rez = 'mentioned'; //false;
                            }
                        }
                    }

                    break;
            }
        }

        return $rez;
    }

    /**
     * Set the user enabled or disabled
     *
     * @param  integer $userId
     * @param  bool $enabled
     *
     * @return array
     */
    public static function setEnabled($userId, $enabled)
    {
        $params = [
            'id' => $userId,
            'enabled' => intval($enabled),
        ];

        return DM\Users::update($params);
    }

    /**
     * Get user colors
     * @return array associative array of userId => color
     */
    public static function getColors()
    {
        $rez = [];

        $recs = DM\UsersGroups::readAll();

        $noColors = [];

        foreach ($recs as &$r) {
            if (empty($r['cfg']['color']) && ($r['type'] == 2)) {
                $noColors[] = &$r;
            }
        }

        if (!empty($noColors)) {
            $params = [
                'luminosity' => 'random',
                'hue' => 'random',
            ];
            $colors = RandomColor::many(sizeof($noColors), $params);

            foreach ($noColors as &$r) {
                $r['cfg']['color'] = array_shift($colors);
                User::setUserConfigParam('color', $r['cfg']['color'], $r['id']);
            }
            unset($r);
        }
        $uid = User:: getId();

        foreach ($recs as $r) {
            $rez[$r['id']] = empty($r['cfg']['customColor'][$uid]) ? @$r['cfg']['color'] : $r['cfg']['customColor'][$uid];
        }

        return $rez;
    }
}
