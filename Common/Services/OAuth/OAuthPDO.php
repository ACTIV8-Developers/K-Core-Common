<?php

namespace App\Services\OAuth;

use OAuth2\Storage\Pdo;

class OAuthPDO extends Pdo
{
    protected ?array $userCache = null;

    /**
     * @param string $username
     * @return array|bool
     */
    public function getUserDetails($username)
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }

        $stmt = $this->db->prepare($sql = sprintf('SELECT * from %s where username=:username', $this->config['user_table']));
        $stmt->execute(array('username' => $username));

        if (!$userInfo = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            return false;
        }

        $id = $userInfo['user_id'];
        unset($userInfo['user_id']);

        // the default behavior is to use "username" as the user_id
        $this->userCache = array_merge(array(
            'user_id' => $username,
            'id' => $id
        ), $userInfo);

        return $this->userCache;
    }

    protected function hashPassword($password): string
    {
        return Hasher::getInstance()->hashPassword($password);
    }

    protected function checkPassword($user, $password): bool
    {
        return Hasher::getInstance()->checkPassword($password, $user['password']);
    }
}