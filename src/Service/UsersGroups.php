<?php

namespace Casebox\CoreBundle\Service;

use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Casebox\CoreBundle\Entity\UsersGroups as UsersGroupsEntity;
use Symfony\Component\DependencyInjection\Container;

/**
 * Class UsersGroups
 */
class UsersGroups
{
    use TranslatorTrait;

    /**
     * Get the child list to be displayed in user management window in left tree
     *
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function getChildren(array $p = [])
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        $rez = [];

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }
        $path = explode('/', $p['path']);
        $id = array_pop($path);
        $nodeType = null;

        $dbs = Cache::get('casebox_dbs');

        if (is_numeric($id)) {
            $r = DM\UsersGroups::read($id);

            if (!empty($r)) {
                $nodeType = $r['type'];
            }
        }

        // users out of a group
        if ($id == -1) {
            $res = $dbs->query(
                'SELECT
                    id,
                    u.cid,
                    name,
                    first_name,
                    last_name,
                    sex,
                    `enabled`
                FROM users_groups u
                LEFT JOIN users_groups_association a ON u.id = a.user_id
                WHERE u.`type` = 2
                    AND u.`system` = 0
                    AND u.did IS NULL
                    AND a.group_id IS NULL
                ORDER BY 3, 2'
            );

            while ($r = $res->fetch()) {
                $r['loaded'] = true;
                $rez[] = $r;
            }
            unset($res);

        } elseif (is_null($nodeType)) { /* root node childs*/
            $res = $dbs->query(
                'SELECT
                    id,
                    name,
                    `type`,
                    `system`,
                    (SELECT count(*)
                        FROM users_groups_association a
                        JOIN users_groups u ON a.user_id = u.id
                        AND u.did IS NULL
                        WHERE group_id = g.id) `loaded`
                FROM users_groups g
                WHERE `type` = 1
                    AND `system` = 0
                ORDER BY 3, 2'
            );

            while ($r = $res->fetch()) {
                $r['iconCls'] = 'icon-users';
                $r['expanded'] = true;
                $r['loaded'] = empty($r['loaded']);

                $rez[] = $r;
            }
            unset($res);
            $rez[] = [
                'nid' => -1,
                'name' => $this->trans('Users_without_group'),
                'iconCls' => 'icon-users',
                'type' => 1,
                'expanded' => true,
            ];
        } else {
            // group users
            $res = $dbs->query(
                'SELECT
                    u.id
                    ,u.cid
                    ,u.name
                    ,first_name
                    ,last_name
                    ,sex
                    ,enabled
                FROM users_groups_association a
                JOIN users_groups u ON a.user_id = u.id
                WHERE a.group_id = $1
                    AND u.did IS NULL
                ORDER BY 4, 5, 3',
                $id
            );

            while ($r = $res->fetch()) {
                $r['loaded'] = true;
                $rez[] = $r;
            }
            unset($res);
        }

        // collapse first and last names into title
        for ($i = 0; $i < sizeof($rez); $i++) {
            $rez[$i]['title'] = User::getDisplayName($rez[$i]);

            unset($rez[$i]['first_name']);
            unset($rez[$i]['last_name']);

            if (isset($rez[$i]['id'])) {
                $rez[$i]['nid'] = $rez[$i]['id'];
                unset($rez[$i]['id']);
            }
        }

        return $rez;
    }

    /**
     * Associating a user to a group
     */
    public function associate($user_id, $group_id)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'SELECT user_id FROM users_groups_association WHERE user_id = $1 AND group_id = $2',
            [$user_id, $group_id]
        );
        if ($res->fetch()) {
            throw new \Exception($this->trans('UserAlreadyInOffice'));
        }
        unset($res);
        $dbs->query(
            'INSERT INTO users_groups_association (user_id, group_id, cid) VALUES ($1, $2, $3)',
            [
                $user_id,
                $group_id,
                User::getId(),
            ]
        );

        Security::calculateUpdatedSecuritySets();

        Solr\Client::runBackgroundCron();

        return ['success' => true];
    }

    /**
     * Deassociating a user from a group
     */
    public function deassociate($user_id, $group_id)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'DELETE FROM users_groups_association WHERE user_id = $1 AND group_id = $2',
            [$user_id, $group_id]
        );

        Security::calculateUpdatedSecuritySets();

        Solr\Client::runBackgroundCron();

        //return if the user is associated to another office,
        //otherwise it shoul be added to Users out of office folder
        $outOfGroup = true;
        $res = $dbs->query(
            'SELECT group_id FROM users_groups_association WHERE user_id = $1 LIMIT 1',
            $user_id
        );
        if ($r = $res->fetch()) {
            $outOfGroup = false;
        }

        return ['success' => true, 'outOfGroup' => $outOfGroup];
    }

    /**
     * Add a new user
     *
     */
    public function addUser($p)
    {
        if (!User::isVerified()) {
            return [
                'success' => false,
                'verify' => true,
            ];
        }

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $rez = [
            'success' => false,
            'msg' => $this->trans('Missing_required_fields'),
        ];

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        $dbs = Cache::get('casebox_dbs');

        $p['name'] = strip_tags($p['name']);
        $p['name'] = trim($p['name']);

        if ((empty($p['password']) || empty($p['confirm_password'])) && $p['ps'] == 2) {
             return $rez;
        }

        $p1 = empty($p['password']) ? '' : $p['password'];
        $p2 = empty($p['confirm_password']) ? '' : $p['confirm_password'];

        if (empty($p['name']) || ($p1 != $p2)) {
            return $rez;
        }

        // Validate input params
        if (!preg_match('/^[a-z\.0-9_]+$/i', $p['name'])) {
            return [
                'success' => false,
                'msg' => 'Invalid username. Use only letters, digits, "dot" and/or "underscore".',
            ];
        }

        $p['first_name'] = Purify::humanName($p['first_name']);
        $p['last_name'] = Purify::humanName($p['last_name']);

        if (!empty($p['email'])) {
            if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'msg' => $this->trans('InvalidEmail'),
                ];
            }
        }

        // Check if user with such email doesn exist
        $user_id = DM\Users::getIdByEmail($p['email']);
        if (!empty($user_id)) {
                       return [
                'success' => false,
                'msg' => 'User by that email already exists',
            ];
        }

        // Check user existance, if user already exists but is deleted
        // then its record will be used for new user
        $user_id = DM\Users::getIdByName($p['name']);
        if (!empty($user_id)) {
                                   return [
                'success' => false,
                'msg' => 'User by that username already exists',
            ];
        }

        $params = [
            'name' => $p['name'],
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name'],
            'cid' => User::getId(),
            'language_id' => 1,
            'email' => $p['email'],
            'salt' => md5(uniqid(null, true)),
            'roles' => json_encode([UsersGroupsEntity::ROLE_USER => UsersGroupsEntity::ROLE_USER]),
            'cdate' => time(),
        ];

        if (!empty($p['password']) && !empty($p['psw_setup']['ps']) && ($p['psw_setup']['ps'] == 2)) {
            $params['password'] = Cache::get('symfony.container')->get('casebox_core.service_auth.authentication')
                ->getEncodedPasswordAndSalt($p['password'], $params['salt']);
        }

        $user_id = DM\Users::getIdByName($p['name'], false);
        if (!empty($user_id)) {
            //update
            $params['id'] = $user_id;
            DM\Users::update($params);

            // In case it was a deleted user we delete all old accesess
            $dbs->query('DELETE FROM users_groups_association WHERE user_id = $1', $user_id);
            $dbs->query('DELETE FROM tree_acl WHERE user_group_id = $1', $rez['data']['id']);
        } else {
            //create
            $user_id = DM\Users::create($params);
        }

        $rez = [
            'success' => true,
            'data' => ['id' => $user_id],
        ];
        $p['id'] = $user_id;

        // associating user to group if group was specified
        if (isset($p['group_id']) && is_numeric($p['group_id'])) {
            $params = [
                $user_id,
                $p['group_id'],
                User::getId(),
            ];

            $dbs->query(
                'INSERT INTO users_groups_association (user_id, group_id, cid) VALUES($1, $2, $3)
                 ON duplicate KEY UPDATE cid = $3',
                $params
            );

            $rez['data']['group_id'] = $p['group_id'];
        } else {
            $rez['data']['group_id'] = 0;
        }

        // Check if send invite is set and create notification
        if (!empty($p['psw_setup']['ps']) && ($p['psw_setup']['ps'] == 1)) {
            $this->sendResetPasswordMail($user_id, 'invite');
        }

        Security::calculateUpdatedSecuritySets();

        $this->logAction('user_create', array('userId' => $user_id,'userName' => $p['name'], 'text'=>($p['first_name'] . ' ' . $p['last_name'].'\'s account has been created')));

        Solr\Client::runBackgroundCron();

        return $rez;
    }

    /**
     * Delete a user from user management window
     */
    public function deleteUser($user_id)
    {
		if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'UPDATE users_groups SET ddate = CASE WHEN ddate IS NULL THEN 1 ELSE NULL END WHERE id = $1',
            [
                $user_id,
            ]
        );

        //TODO: destroy user session if logged in
        return [
            'success' => $res->rowCount() ? true : false,
            'data' => [$user_id, User::getId()],
        ];
    }

    /**
     * Delete a group from user management window
     */
    public function deleteGroup($group_id)
    {
        if (!Security::canEditUser($group_id)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        /* Delete group record. All security rules with this group wil be deleted by foreign key.
        On deleting a group also the users associations are deleted by the foreign key
        and corresponding security sets are marked, by trigger, as updated.
        */
        $dbs = Cache::get('casebox_dbs');

        $dbs->query('DELETE FROM users_groups WHERE id = $1 AND `type` = 1', $group_id);
        /* call the recalculation method for security sets. */
        Security::calculateUpdatedSecuritySets();

        Solr\Client::runBackgroundCron();

        return ['success' => true];
    }

    /**
     * Retreive user details data to be displayed in user details window
     */
    public function getUserData($p)
    {
        if (isset($p['assign'])) {
          if ($p['assign'] != 1) {
            if (!User::isVerified()) {
                return ['success' => false, 'verify' => true];
            }

            if ((User::getId() != $p['data']['id']) && !Security::canManage()) {
                throw new \Exception($this->trans('Access_denied'));
            }
          }
        }

        $user_id = $p['data']['id'];
        $rez = ['success' => false, 'msg' => $this->trans('Wrong_id')];

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'SELECT id
                ,cid
                ,name
                ,first_name
                ,last_name
                ,sex
                ,email
                ,enabled
                ,data
                ,last_action_time
                ,cdate
                ,cid
				,ddate
            FROM users_groups u
            WHERE id = $1',
            $user_id
        );

        if ($r = $res->fetch()) {
            $r['title'] = User::getDisplayName($r);
            $r['data'] = Util\toJSONArray($r['data']);
            $r['last_action_time'] = Util\formatMysqlTime($r['last_action_time']);
            $r['cdate'] = Util\formatMysqlTime($r['cdate']);
            $r['owner'] = User::getDisplayName($r['cid']);

            $rez = ['success' => true, 'data' => $r];
        }
        unset($res);
        if ($rez['success'] == false) {
            throw new \Exception($this->trans('Wrong_id'));
        }

        $rez['data']['template_id'] = User::getTemplateId();

        return $rez;
    }

    /**
     * get display data for given ids
     *
     * @param string|array $ids
     *
     * @return array
     */
    public static function getDisplayData($ids)
    {
        $rez = [];

        $ids = Util\toNumericArray($ids);
        if (!empty($ids)) {
            if (Cache::exist('UsersGroupsDisplayData')) {
                $cdd = Cache::get('UsersGroupsDisplayData');
            } else {
                $cdd = DataModel\UsersGroups::getDisplayData();
                Cache::set('UsersGroupsDisplayData', $cdd);
            }

            $rez = array_intersect_key($cdd, array_flip($ids));
        }

        return $rez;
    }

    /**
     * Get access data for a user to be displayed in user management window
     */
    public function getAccessData($user_id = false)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }
        $user_id = $this->extractId($user_id);
        $rez = $this->getUserData(['data' => ['id' => $user_id]]);

        $rez['data']['groups'] = [];

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query('SELECT a.group_id FROM users_groups_association a WHERE user_id = $1', $user_id);

        while ($r = $res->fetch()) {
            $rez['data']['groups'][] = $r['group_id'];
        }
        unset($res);

        // set tsv status
        $tsv = User::getTSVConfig($user_id);
        $rez['data']['tsv'] = empty($tsv['method']) ? 'none' : $this->trans('TSV_'.$tsv['method']);
		$rez['data']['tsvdisabled'] = empty($rez['data']['ddate']) ? 'Required': 'Not Required';

		return $rez;
    }

    /**
     * Save access data specified for a user in UserManagement form (groups association)
     *
     * @param string|array $p
     *
     * @return array
     * @throws \Exception
     */
    public function saveAccessData($p)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (!Security::canManage()) {
            throw new \Exception($this->trans('Access_denied'));
        }
        $p = (Array)$p;
        @$user_id = $this->extractId($p['id']);

        /*
            analize groups:
            - for newly associated groups the access should be updated
            - for deassociated groups the access also should be reviewed
        */

        // get current user groups
        $current_groups = UsersGroups::getGroupIdsForUser($user_id);
        $updating_groups = Util\toNumericArray(@$p['groups']);

        $new_groups = array_diff($updating_groups, $current_groups);
        $deleting_groups = array_diff($current_groups, $updating_groups);

        $dbs = Cache::get('casebox_dbs');

        foreach ($new_groups as $group_id) {
            $dbs->query(
                'INSERT INTO users_groups_association (user_id, group_id, cid)
                VALUES($1, $2, $3) ON DUPLICATE KEY
                UPDATE uid = $3',
                [
                    $user_id,
                    $group_id,
                    User::getId(),
                ]
            );
        }

        if (!empty($deleting_groups)) {
            $dbs->query(
                'DELETE FROM users_groups_association WHERE user_id = $1 AND group_id IN ('.
                implode(', ', $deleting_groups).')',
                $user_id
            );
        }

        Security::calculateUpdatedSecuritySets($user_id);

        Solr\Client::runBackgroundCron();

        return ['success' => true];
    }

    /**
     * Get an array of group ids for specified user.
     * If no user is passed then current logged user is analized.
     *
     * @param bool $user_id
     *
     * @return array
     */
    public static function getGroupIdsForUser($user_id = false)
    {
        if ($user_id === false) {
            $user_id = User::getId();
        }

        $groups = [];

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'SELECT group_id FROM users_groups_association WHERE user_id = $1',
            $user_id
        );

        while ($r = $res->fetch()) {
            $groups[] = $r['group_id'];
        }
        unset($res);

        return $groups;
    }

    /**
     * Change user password.
     *
     * @param array $p
     * @param bool|true $verify
     *
     * @return array
     * @throws \Exception
     */
    public function changePassword(array $p, $verify = true)
    {
        if (!User::isVerified() && $verify) {
            return ['success' => false, 'verify' => true];
        }

        // Password could be changed by: admin, user owner, user himself
        if (empty($p['password']) || ($p['password'] != $p['confirmpassword'])) {
            throw new \Exception('New password and confirm passwords much match');
        }
        $uid = $this->extractId($p['id']);

        /** @var Container $container */
        $container = Cache::get('symfony.container');
        $em = $container->get('doctrine.orm.entity_manager');
        $encoderFactory = $container->get('security.encoder_factory');

        $username = User::getUsername($uid);

        // Check for old password if users changes password for himself
        if (User::getId() == $uid) {
            $user = $em->getRepository('CaseboxCoreBundle:UsersGroups')->findUserByUsername($username);
            if (!$user instanceof UsersGroupsEntity) {
                return [
                  'success' => false,
                  'verify'  => true,
                  'message' => $this->trans('WrongCurrentPassword'),
                ];
            }

            if (strlen($user->getPassword()) <= 32) {
                // Old password behavior
                $encodedPass = md5('aero'.$p['currentpassword']);
            } else {
                $encoder = $encoderFactory->getEncoder($user);
                $encodedPass = $encoder->encodePassword($p['currentpassword'], $user->getSalt());
            }

            if ($encodedPass !== $user->getPassword()) {
                return [
                  'success' => false,
                  'verify'  => true,
                  'message' => $this->trans('WrongCurrentPassword'),
                ];
            }
        }

		$weakness = $this->passwordStrength($p['password'],$username);

        if($weakness) {
                return [
                  'success' => false,
                  'verify'  => true,
                  'message' => 'Password does not meet minimum requirements: ' . $weakness
                ];
        }

        if (!Security::canEditUser($uid) && $verify) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $this->updatePassword($username, $p['password']);

        Session::clearUserSessions($uid);

        return ['success' => true];
    }

    public function updatePassword($username, $password)
    {
        /** @var Container $container */
        $container = Cache::get('symfony.container');
        $em = $container->get('doctrine.orm.entity_manager');
        $auth = $container->get('casebox_core.service_auth.authentication');

        $user = $em->getRepository('CaseboxCoreBundle:UsersGroups')->findUserByUsername($username);
        if (!$user instanceof UsersGroupsEntity) {
            return false;
        }

		$weakness = $this->passwordStrength($password,$username);

        if($weakness) {
                return [
                  'success' => false,
                  'verify'  => true,
                  'message' => 'Password does not meet minimum requirements: ' . $weakness
                ];
        }

        $salt = $user->getSalt();

        if (empty($salt)) {
            $salt = $auth->generateSalt();
        }

        $encodedPass = $auth->getEncodedPasswordAndSalt($password, $salt);

        $user->setSalt($salt);
        $user->setPassword($encodedPass);
        $user->setRecoverHash(null); //DSTOUDT/APPRIO GET RID OF Recovery Hash so as to not ask user to reset

        $em->flush();

        return true;
    }

    /**
     * Send recovery password email for given user id so that the user can set new password and enter the system
     *
     * @param integer|string $userId
     * @param string $template
     *
     * @return bool
     */
    public static function sendResetPasswordMail($userId, $template = 'recover')
    {
        if ($template !== 'recover') {
            if (!is_numeric($userId) || (User::isLogged() && !Security::canEditUser($userId))) {
                return false;
            }
        }

        $userData = User::getPreferences($userId);
        $userEmail = User::getEmail($userData);

        if (empty($userEmail)) {
            return false;
        }

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        // generating invite hash and sending mail
        $hash = User::generateRecoveryHash($userId, $userId.$userEmail.date(DATE_ISO8601));

        /** @var Container $container */
        $container = Cache::get('symfony.container');

        $config = Cache::get('platformConfig');
        $env = $config['coreName'];
        $baseUrl = $config['server_name'];

        $href = $baseUrl.'c/'.$env.'/recover/reset-password?token='.$hash;

        // Replacing placeholders in template and subject
        $vars = [
            'projectTitle' => $configService->getProjectName(),
            'name' => User::getDisplayName($userData),
            'fullName' => User::getDisplayName($userData),
            'username' => User::getUsername($userData),
            'userEmail' => $userEmail,
            'creatorFullName' => User::getDisplayName(),
            'creatorUsername' => User::getUsername(),
            'creatorEmail' => User::getEmail(),
            'href' => $href,
            'link' => '<a href="'.$href.'" >'.$href.'</a>',
        ];

        $twig = $container->get('twig');

        switch ($template) {
            case 'invite':
                $mail = $twig->render('CaseboxCoreBundle:email:email_invite.html.twig', $vars);
                $subject = self::trans('MailInviteSubject');

                break;

            case 'recover':
                $mail = $twig->render('CaseboxCoreBundle:email:password_recovery_email_en.html.twig', $vars);
                $subject = self::trans('MailRecoverSubject');

                break;

            default:
                return false;
        }

        if (empty($mail)) {
            return false;
        }

        return @System::sendMail($userEmail, $subject, $mail);
    }

    /**
     * Shortcut to previous function to return json response
     *
     * @param int $userId
     *
     * @return array
     */
    public function sendResetPassMail($userId)
    {
        return [
            'success' => $this->sendResetPasswordMail($userId),
        ];
    }

    /**
     * @param integer $userId
     *
     * @return array
     * @throws \Exception
     */
    public function disableTSV($userId)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        if (is_nan($userId)) {
            throw new \Exception($this->trans('Wrong_input_data'));
        }

        if (!Security::canEditUser($userId)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        return User::disableTSV($userId);
    }

    /**
     * Rename user
     *
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function renameUser($p)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        /* username could be changed by: admin or user owner */
        $name = trim(strtolower(strip_tags($p['name'])));
        $matches = preg_match('/^[a-z0-9\._]+$/i', $name);

        if (empty($name) || empty($matches)) {
            throw new \Exception($this->trans('Wrong_input_data'));
        }

        $user_id = $this->extractId($p['id']);

        if (!Security::canEditUser($user_id)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $dbs = Cache::get('casebox_dbs');

        $dbs->query(
            'UPDATE users_groups SET `name` = $2, uid = $3 WHERE id = $1',
            [
                $user_id,
                $name,
                User::getId(),
            ]
        );

        return ['success' => true, 'name' => $name];
    }

    /**
     * Set user enabled or disabled
     *
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function setUserEnabled($p)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        $userId = $this->extractId($p['id']);
        $enabled = !empty($p['enabled']);

        if (!Security::canEditUser($userId)) {
            throw new \Exception($this->trans('Access_denied'));
        }
        $container = Cache::get('symfony.container');
        $em = $container->get('doctrine.orm.entity_manager');
        $encoderFactory = $container->get('security.encoder_factory');

		$username = User::getUsername($userId);
		$fullName = User::getDisplayName($userId);
        $user = $em->getRepository('CaseboxCoreBundle:UsersGroups')->findUserByUsername($username);

        if (!$user instanceof UsersGroupsEntity) {
            return false;
        }
		$user->setLoginSuccessful(0);
		$user->setEnabled(intval($enabled));

		$this->logAction('status_change', array('userId' => $userId,'userName' => $username, 'status' => intval($enabled), 'text'=>($fullName.'\'s account has been set to '). (($enabled)? 'enabled':'disabled')));
        $em->flush();

        return ['success' => true, 'enabled' => $enabled];
    }

    /**
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function renameGroup($p)
    {
        if (!User::isVerified()) {
            return ['success' => false, 'verify' => true];
        }

        $title = Purify::humanName($p['title']);

        if (empty($title)) {
            throw new \Exception($this->trans('Wrong_input_data'));
        }

        $id = $this->extractId($p['id']);

        if (!Security::canEditUser($id)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $dbs = Cache::get('casebox_dbs');

        $dbs->query(
            'UPDATE users_groups SET name = $2, uid = $3 WHERE id = $1 AND type = 1',
            [
                $id,
                $title,
                User::getId(),
            ]
        );

        return ['success' => true, 'title' => $title];
    }

    /**
     * Extract numeric id from a tree node prefixed id
     *
     * @param integer $id
     *
     * @return mixed
     * @throws \Exception
     */
    private function extractId($id)
    {
        if (is_numeric($id)) {
            return $id;
        }
        $a = explode('-', $id);
        $id = array_pop($a);
        if (!is_numeric($id) || ($id < 1)) {
            throw new \Exception($this->trans('Wrong_input_data'));
        }

        return $id;
    }

    function passwordStrength($password, $username = null)
    {
        if (!empty($username))
        {
        //remove the username from the password if present
            $password = str_replace($username, '', $password);
        }

        $password_length = strlen($password);

        if ($password_length < 8)
        {
            return "too short";
        }

        if (!preg_match("#[0-9]+#", $password)) {
            return "does not include at least one number";
        }

        if (!preg_match("#[a-z]+#", $password)) {
            return "does not include at least one lowercase letter";
        }

        if (!preg_match("#[A-Z]+#", $password)) {
            return "does not include at least one uppercase letter";
        }

        if (!preg_match("/[|!@#$%&*\/=?,;.:\-_+~^¨\\\]/", $password)) {
            return "does not include at least one special character";
        }

        return;
    }

    /**
     * add action to log
     *
     * @param string $type
     * @param array $data
     *
     * @return void
     */
    protected function logAction($type, $data)
    {
    	if (!Cache::get('disable_logs', false)) {
    		$uid = User::getId();
    		$params = [
    				'object_id' => 10,
    				'object_pid' => 10,
    				'user_id' => $uid,
    				'action_type' => $type,
    				'data' => Util\jsonEncode($data),
    				//'data' => Util\jsonEncode(array('ip' => $user->getLoginFromIp(), 'failedlogins' => $user->getLoginSuccessful())),
    				'activity_data_db' => Util\jsonEncode($uid),
    		];

    		$p['action_id'] = DM\Log::create($params);
    	}
    }
}
