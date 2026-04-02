<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      https://syspass.org
 * @copyright 2012-2019, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Providers\Auth\Database;

use Exception;
use SP\Core\Crypt\Hash;
use SP\DataModel\UserLoginData;
use SP\Providers\Auth\AuthInterface;
use SP\Services\User\UserLoginResponse;
use SP\Services\User\UserPassService;
use SP\Services\User\UserService;

/**
 * Class Database
 *
 * Autentificación basada en base de datos
 *
 * @package SP\Providers\Auth\Database
 */
final class Database implements AuthInterface
{
    /**
     * @var UserLoginData $userLoginData
     */
    protected $userLoginData;
    /**
     * @var UserService
     */
    private $userService;
    /**
     * @var UserPassService
     */
    private $userPassService;

    /**
     * Database constructor.
     *
     * @param UserService     $userService
     * @param UserPassService $userPassService
     */
    public function __construct(UserService $userService, UserPassService $userPassService)
    {
        $this->userService = $userService;
        $this->userPassService = $userPassService;
    }


    /**
     * Autentificar al usuario
     *
     * @param UserLoginData $userLoginData Datos del usuario
     *
     * @return DatabaseAuthData
     */
    public function authenticate(UserLoginData $userLoginData)
    {
        $this->userLoginData = $userLoginData;

        $authData = new DatabaseAuthData();
        $authData->setAuthGranted($this->isAuthGranted());
        $authData->setAuthenticated($this->authUser());

        return $authData;
    }

    /**
     * Indica si es requerida para acceder a la aplicación
     *
     * @return boolean
     */
    public function isAuthGranted()
    {
        return true;
    }

    /**
     * Authenticate user against the database.
     *
     * Only modern password hashes (bcrypt/argon2) are accepted.
     * Users with legacy MD5/SHA1 hashes (isMigrate=1) must reset their password.
     *
     * @return bool
     */
    protected function authUser()
    {
        try {
            $userLoginResponse = UserService::mapUserLoginResponse($this->userService->getByLogin($this->userLoginData->getLoginUser()));

            $this->userLoginData->setUserLoginResponse($userLoginResponse);

            if ($userLoginResponse->getIsMigrate()) {
                logger('User ' . $userLoginResponse->getLogin() . ' has legacy password hash (isMigrate=1). Password reset required.');

                return false;
            }

            return Hash::checkHashKey($this->userLoginData->getLoginPass(), $userLoginResponse->getPass());
        } catch (Exception $e) {
            processException($e);
        }

        return false;
    }
}