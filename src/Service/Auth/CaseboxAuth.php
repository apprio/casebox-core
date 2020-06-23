<?php

namespace Casebox\CoreBundle\Service\Auth;

use Casebox\CoreBundle\Entity\UsersGroups as UsersGroupsEntity;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\UsersGroups;
use Casebox\CoreBundle\Service\Config;
use Casebox\CoreBundle\Service\Security;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\DataModel as DM;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;

/**
 * Class CaseboxAuth
 */
class CaseboxAuth
{
    /**
     * @var Container
     */
    private $container;

    /**
     * Authentication constructor
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @return EntityManager
     * @throws \Exception
     */
    public function getEm()
    {
        return $this->container->get('doctrine.orm.entity_manager');
    }

    /**
     * @return EncoderFactoryInterface
     * @throws \Exception
     */
    public function getEncoderFactoryInterface()
    {
        return $this->container->get('security.encoder_factory');
    }

    /**
     * @return TokenStorageInterface
     * @throws \Exception
     */
    public function getSecurityContext()
    {
        return $this->container->get('security.token_storage');
    }

    /**
     * @return Session
     * @throws \Exception
     */
    public function getSession()
    {
        return $this->container->get('session');
    }

    /**
     * @param string $username
     * @param string $password
     *
     * @return bool
     * @throws \Exception
     */
    public function authenticate($username, $password)
    {
		$failedMessage = "Invalid Credentials";
		$disabledMessage = "You have exceeded the amount of login attempts. Please contact the system administrator to have your password reset.";
		$user = $this->getEm()->getRepository('CaseboxCoreBundle:UsersGroups')->findUserByUsername($username);

        if (!$user instanceof UsersGroupsEntity) {
            return $failedMessage;
        }

		if (!$user->getEnabled())
		{
		    //Should probably send some message instead of just null - i.e. account has been disabled
			return $disabledMessage;
		}

        $roles = $user->getRoles();
        if (empty($roles)) {
            $roles = [UsersGroupsEntity::ROLE_USER => UsersGroupsEntity::ROLE_USER];
        }

        if (strlen($user->getPassword()) <= 32) {
            // Old password behavior
            $encodedPass = md5('aero'.$password);
        } else {
            $encoder = $this->getEncoderFactoryInterface()->getEncoder($user);
            $encodedPass = $encoder->encodePassword($password, $user->getSalt());
        }
		$user->setLoginFromIp($_SERVER['REMOTE_ADDR']);
        if ($encodedPass !== $user->getPassword()) {
			//Unsuccessful login here - need to log it
			$this->logAction('login_fail', @$user);

			$user->setLoginSuccessful($user->getLoginSuccessful()-1);
			if ($user->getLoginSuccessful() < -4)
			{
				$user->setEnabled(false);
				$failedMessage = $disabledMessage;
			}
			$this->getEm()->flush();
            return $failedMessage;
        }
		$user->setLoginSuccessful(0);
		$user->setLastLogin(time());
		$this->getEm()->flush();
        $session = $this->getSession();

        $env = $this->container->getParameter('kernel.environment');
        $token = new UsernamePasswordToken($user, $password, $env, $roles);
        $this->getSecurityContext()->setToken($token);

        $session->set('main', serialize($token));

        $data = $this->getSessionData($user->getId());

        $session->set('user', $data);

        $session->save();

        return $user;
    }

    /**
     * @return bool|true
     */
    public function logout()
    {
		$user = $this->getEm()->getRepository('CaseboxCoreBundle:UsersGroups')->findUserByUsername(User::getUsername());
        if (!$user instanceof UsersGroupsEntity) {
            return false;
        }
		$user->setLastLogout(time());
		$this->getEm()->flush();


        $anonToken = new AnonymousToken('theTokensKey', 'anon.', []);
        $this->getSecurityContext()->setToken($anonToken);
        $this->getSession()->invalidate();

        return true;
    }

    public function getSessionData($userId)
    {
        $rez = User::getPreferences($userId);

        if (!empty($rez)) {
            $rez['admin'] = Security::isAdmin($userId);
            $rez['manage'] = Security::canManage($userId);

            $rez['first_name'] = htmlentities($rez['first_name'], ENT_QUOTES, 'UTF-8');
            $rez['last_name'] = htmlentities($rez['last_name'], ENT_QUOTES, 'UTF-8');

            //set default theme
            if (empty($rez['cfg']['theme'])) {
                $rez['cfg']['theme'] = 'classic';
            }

            // do not expose security params
            unset($rez['cfg']['security']);

            // set user groups
            $rez['groups'] = UsersGroups::getGroupIdsForUser();
        }

        return $rez;
    }

	/**
     * add action to log
     *
     * @param string $type
     * @param array $params
     *
     * @return void
     */
    protected function logAction($type, $user)
    {
        if (!Cache::get('disable_logs', false)) {
          $params = [
            'object_id' => 10,
            'object_pid' => 10,
            'user_id' => $user->getId(),
            'action_type' => $type,
            'data' => Util\jsonEncode(array('ip' => $user->getLoginFromIp(), 'failedlogins' => $user->getLoginSuccessful())),
            'activity_data_db' => Util\jsonEncode($user),
        ];

        $p['action_id'] = DM\Log::create($params);
        }
    }

    /**
     * @param bool $throw Throw an exception if user nor found
     *
     * @return UsersGroupsEntity
     * @throws \Exception
     */
    public function isLogged($throw = true)
    {
        $user = null;

        if (!$this->getSecurityContext() instanceof TokenStorage) {
            throw new \Exception('User not authenticated.', 401);
        }

        $token = $this->getSecurityContext()->getToken();

        if (!is_null($token)) {
            $user = $token->getUser();
        }

        if ($throw && !$user instanceof UsersGroupsEntity) {
            throw new \Exception('User not authenticated.', 401);
        }

        if (!$user instanceof UsersGroupsEntity) {
            $user = null;
        }

        return $user;
    }

    /**
     * @param string $password Plain password
     * @param string|null $salt
     *
     * @return array
     */
    public function getEncodedPasswordAndSalt($password, $salt = null)
    {
        if (empty($salt)) {
            $salt = $this->generateSalt();
        }
        $encoder = $this->getEncoderFactoryInterface()->getEncoder(new UsersGroupsEntity());

        return $encoder->encodePassword($password, $salt);
    }

    /**
     * @return string
     */
    public function generateSalt()
    {
        return md5(uniqid(null, true));
    }
}
