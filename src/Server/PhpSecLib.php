<?php
/* (c) Anton Medvedev <anton@elfet.ru>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer\Server;

use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;

class PhpSecLib extends AbstractServer
{
    /**
     * @var \phpseclib\Net\SFTP
     */
    private $sftp;


    /**
     * Used to tells if it's necessary to restart the connection
     * @var int
     */
    private $lastConnectionRefresh = 0;

    /**
     * Array of created directories during upload.
     * @var array
     */
    private $directories = [];

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        $this->sftp = new SFTP($this->config->getHost(), $this->config->getPort());

        //long timeout since we might use very long commands such as tar.xz or composer update
        $this->sftp->setTimeout(10000);

        switch ($this->config->getAuthenticationMethod()) {
            case Configuration::AUTH_BY_PASSWORD:

                if (!$this->sftp->login($this->config->getUser(), $this->config->getPassword())) {
                    throw new \RuntimeException('Could not login with PhpSecLib.');
                }

                break;

            case Configuration::AUTH_BY_PUBLIC_KEY:

                $key = new RSA();
                $key->setPassword($this->config->getPassPhrase());
                $key->loadKey(file_get_contents($this->config->getPrivateKey()));

                if (!$this->sftp->login($this->config->getUser(), $key)) {
                    throw new \RuntimeException('Could not login with PhpSecLib.');
                }

                break;

            case Configuration::AUTH_BY_PEM_FILE:

                $key = new RSA();
                $key->loadKey(file_get_contents($this->config->getPemFile()));
                if (!$this->sftp->login($this->config->getUser(), $key)) {
                    throw new \RuntimeException('Could not login with PhpSecLib.');
                }

                break;

            default:
                throw new \RuntimeException('You need to specify authentication method.');
        }
    }

    /**
     * Check if not connected and connect.
     */
    public function checkConnection()
    {
        //connect if not used for 10 seconds
        if (microtime(true) - $this->lastConnectionRefresh > 10 ) {
            $this->connect();
        }

        $this->lastConnectionRefresh = microtime(true);
    }

    /**
     * {@inheritdoc}
     */
    public function run($command)
    {
        $this->checkConnection();

        $result = $this->sftp->exec($command);

        if ($this->sftp->getExitStatus() !== 0) {
            throw new \RuntimeException($this->sftp->getStdError());
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function upload($local, $remote)
    {
        $this->checkConnection();

        $dir = dirname($remote);

        if (!isset($this->directories[$dir])) {
            $this->sftp->mkdir($dir, -1, true);
            $this->directories[$dir] = true;
        }

        if (!$this->sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE)) {
            throw new \RuntimeException(implode($this->sftp->getSFTPErrors(), "\n"));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function download($local, $remote)
    {
        $this->checkConnection();

        if (!$this->sftp->get($remote, $local)) {
            throw new \RuntimeException(implode($this->sftp->getSFTPErrors(), "\n"));
        }
    }
}
