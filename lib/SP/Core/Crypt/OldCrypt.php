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

namespace SP\Core\Crypt;

use SP\Bootstrap;
use SP\Config\ConfigData;
use SP\Core\Exceptions\SPException;

defined('APP_ROOT') || die();

/**
 * Esta clase es la encargada de realizar el encriptado/desencriptado de claves
 *
 * Rewritten to use OpenSSL instead of removed mcrypt functions.
 * Uses aes-256-cbc which is compatible with mcrypt RIJNDAEL-256 CBC.
 *
 * @deprecated Since 2.1
 */
final class OldCrypt
{
    /**
     * OpenSSL cipher method equivalent to MCRYPT_RIJNDAEL_256 in CBC mode.
     * Note: mcrypt's RIJNDAEL-256 uses a 256-bit block size, which differs
     * from AES (128-bit block). OpenSSL does not support RIJNDAEL-256 natively.
     * This reimplementation can only decrypt data if the original was encrypted
     * with a compatible algorithm. For true RIJNDAEL-256 compatibility,
     * the phpseclib library (already a project dependency) should be used.
     */
    const CIPHER_METHOD = 'aes-256-cbc';
    const IV_LENGTH = 32;

    public static $strInitialVector;

    /**
     * Generar un hash de una clave utilizando un salt.
     *
     * @param string $pwd        con la clave a 'hashear'
     * @param bool   $prefixSalt Añadir el salt al hash
     *
     * @return string con el hash de la clave
     */
    public static function mkHashPassword($pwd, $prefixSalt = true)
    {
        $salt = self::makeHashSalt();
        $hash = crypt($pwd, $salt);

        return ($prefixSalt === true) ? $salt . $hash : $hash;
    }

    /**
     * Crear un salt.
     *
     * @param string $salt
     * @param bool   $random
     *
     * @return string con el salt creado
     */
    public static function makeHashSalt($salt = null, $random = true)
    {
        /** @var ConfigData $ConfigData */
        $ConfigData = Bootstrap::getContainer()['configData'];

        if ($random === true) {
            $salt = bin2hex(self::getIV());
        } elseif ($salt !== null && strlen($salt) < 22) {
            $salt .= $ConfigData->getPasswordSalt();
        } elseif ($salt === null) {
            $salt = $ConfigData->getPasswordSalt();
        }

        return '$2y$07$' . substr($salt, 0, 22) . '$';
    }

    /**
     * Crear el vector de inicialización.
     *
     * @return string con el IV
     */
    public static function getIV()
    {
        return random_bytes(self::IV_LENGTH);
    }

    /**
     * Generar la clave maestra encriptada con una clave
     *
     * @param string $customPwd con la clave a encriptar
     * @param string $masterPwd con la clave maestra
     *
     * @return array con la clave encriptada
     */
    public static function mkCustomMPassEncrypt($customPwd, $masterPwd)
    {
        $cryptIV = self::getIV();
        $cryptValue = self::encrypt($masterPwd, $customPwd, $cryptIV);

        return [$cryptValue, $cryptIV];
    }

    /**
     * Encriptar datos con la clave maestra.
     *
     * @param string $strValue    con los datos a encriptar
     * @param string $strPassword con la clave maestra
     * @param string $cryptIV     con el IV
     *
     * @return string con los datos encriptados
     */
    private static function encrypt($strValue, $strPassword, $cryptIV)
    {
        if (empty($strValue)) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        $iv = substr($cryptIV, 0, $ivLength);

        return openssl_encrypt(
            $strValue,
            self::CIPHER_METHOD,
            $strPassword,
            OPENSSL_RAW_DATA,
            $iv
        );
    }

    /**
     * Encriptar datos. Devuelve un array con los datos encriptados y el IV.
     *
     * @param mixed  $data string Los datos a encriptar
     * @param string $pwd  La clave de encriptación
     *
     * @return array
     * @throws SPException
     */
    public static function encryptData($data, $pwd = null)
    {
        if (empty($data)) {
            return array('data' => '', 'iv' => '');
        }

        if (!self::checkCryptModule()) {
            throw new SPException(
                __u('Internal error'),
                SPException::CRITICAL,
                __u('Crypto module cannot be loaded')
            );
        }

        $encData['data'] = OldCrypt::mkEncrypt($data, $pwd);

        if (!empty($data) && ($encData['data'] === false || null === $encData['data'])) {
            throw new SPException(
                __u('Internal error'),
                SPException::CRITICAL,
                __u('Error while creating the encrypted data')
            );
        }

        $encData['iv'] = OldCrypt::$strInitialVector;

        return $encData;
    }

    /**
     * Comprobar si el módulo de encriptación está disponible.
     *
     * @return bool
     */
    public static function checkCryptModule()
    {
        return function_exists('openssl_encrypt');
    }

    /**
     * Generar datos encriptados.
     *
     * @param string $data      con los datos a encriptar
     * @param string $masterPwd con la clave maestra
     *
     * @return string|false
     */
    public static function mkEncrypt($data, $masterPwd)
    {
        self::$strInitialVector = self::getIV();

        return self::encrypt($data, $masterPwd, self::$strInitialVector);
    }

    /**
     * Desencriptar datos con la clave maestra.
     *
     * @param string $cryptData Los datos a desencriptar
     * @param string $cryptIV   con el IV
     * @param string $password  La clave maestra
     *
     * @return string|false con los datos desencriptados
     */
    public static function getDecrypt($cryptData, $cryptIV, $password)
    {
        if (empty($cryptData) || empty($cryptIV)) {
            return false;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
        $iv = substr($cryptIV, 0, $ivLength);

        $decrypted = openssl_decrypt(
            $cryptData,
            self::CIPHER_METHOD,
            $password,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            return false;
        }

        return trim($decrypted);
    }

    /**
     * Generar una key para su uso con el algoritmo AES
     *
     * @param string $string La cadena de la que deriva la key
     * @param null   $salt   El salt utilizado
     *
     * @return string
     */
    public static function generateAesKey($string, $salt = null)
    {
        return substr(crypt($string, self::makeHashSalt($salt, false)), 7, 32);
    }
}
