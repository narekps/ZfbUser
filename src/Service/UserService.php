<?php

namespace ZfbUser\Service;

use Zend\Hydrator\ClassMethods as ClassMethodsHydrator;
use ZfbUser\Adapter\AdapterInterface;
use ZfbUser\Entity\UserInterface;
use ZfbUser\Options\ModuleOptionsInterface;
use ZfbUser\AuthenticationResult;

/**
 * Class UserService
 *
 * @package ZfbUser\Service
 */
class UserService
{
    /**
     * @var AdapterInterface
     */
    private $authAdapter;

    /**
     * @var ModuleOptionsInterface
     */
    private $moduleOptions;

    /**
     * @var MailSenderInterface
     */
    private $mailSender;

    /**
     * @var TokenService
     */
    private $tokenService;

    /**
     * UserService constructor.
     *
     * @param \ZfbUser\Adapter\AdapterInterface       $authAdapter
     * @param \ZfbUser\Options\ModuleOptionsInterface $moduleOptions
     * @param \ZfbUser\Service\MailSenderInterface    $mailSender
     * @param \ZfbUser\Service\TokenService           $tokenService
     */
    public function __construct(
        AdapterInterface $authAdapter,
        ModuleOptionsInterface $moduleOptions,
        MailSenderInterface $mailSender,
        TokenService $tokenService
    ) {
        $this->authAdapter = $authAdapter;
        $this->moduleOptions = $moduleOptions;
        $this->mailSender = $mailSender;
        $this->tokenService = $tokenService;
    }

    /**
     * @return AdapterInterface
     */
    public function getAuthAdapter(): AdapterInterface
    {
        return $this->authAdapter;
    }

    /**
     * @return ModuleOptionsInterface
     */
    public function getModuleOptions(): ModuleOptionsInterface
    {
        return $this->moduleOptions;
    }

    /**
     * @param array $data
     *
     * @return null|\ZfbUser\Entity\UserInterface
     * @throws \ReflectionException
     * @throws \ZfbUser\Service\Exception\TemplateNotFoundException
     * @throws \ZfbUser\Service\Exception\UnsupportedTokenTypeException
     * @throws \ZfbUser\Service\Exception\UserExistsException
     */
    public function register(array $data): ?UserInterface
    {
        $className = $this->getModuleOptions()->getUserEntityClass();
        $hydrator = new ClassMethodsHydrator();

        /** @var UserInterface $user */
        $user = $hydrator->hydrate($data, (new \ReflectionClass($className))->newInstanceWithoutConstructor());

        $existUser = $this->getAuthAdapter()->getRepository()->getUserByIdentity($user->getIdentity());
        if ($existUser instanceof UserInterface) {
            throw new Exception\UserExistsException();
        }

        $user->setIdentityConfirmed(false);
        $user->setCredential($this->getAuthAdapter()->cryptCredential($user->getCredential()));
        $user = $this->getAuthAdapter()->getMapper()->insert($user);

        if ($this->moduleOptions->isEnableIdentityConfirmation()) {
            $this->sendConfirmationCode($user);
        }

        return $user;
    }

    /**
     * Подтверждение уточной записи
     *
     * @param string $identity
     * @param string $code
     *
     * @return \ZfbUser\AuthenticationResult
     */
    public function confirmIdentity(string $identity, string $code): AuthenticationResult
    {
        $resultCode = null;
        $user = $this->getAuthAdapter()->getRepository()->getUserByIdentity($identity);
        if (!$user) {
            $resultCode = AuthenticationResult::FAILURE_IDENTITY_NOT_FOUND;
        } elseif ($user->isIdentityConfirmed() === true) {
            $resultCode = AuthenticationResult::SUCCESS;
        } else {
            $isValid = $this->tokenService->checkToken($user, $code, TokenService::TYPE_CONFIRMATION, true);
            if ($isValid === true) {

                try {
                    $user->setIdentityConfirmed(true);
                    $this->getAuthAdapter()->getMapper()->update($user);
                    $resultCode = AuthenticationResult::SUCCESS;
                } catch (\Exception $ex) {
                    $resultCode = AuthenticationResult::FAILURE_IDENTITY_CONFIRMATION;
                }

            } else {
                $resultCode = AuthenticationResult::FAILURE_TOKEN_INVALID;
            }
        }

        $messages = [AuthenticationResult::MESSAGE_TEMPLATES[ $resultCode ]];

        return new AuthenticationResult($resultCode, $user, $messages);
    }

    /**
     * @param string $identity
     * @param string $code
     * @param string $newPassword
     *
     * @return \ZfbUser\AuthenticationResult
     */
    public function resetPassword(string $identity, string $code, string $newPassword): AuthenticationResult
    {
        $resultCode = null;
        $user = $this->getAuthAdapter()->getRepository()->getUserByIdentity($identity);
        if (!$user) {
            $resultCode = AuthenticationResult::FAILURE_IDENTITY_NOT_FOUND;
        } elseif ($user->isIdentityConfirmed() !== true) {
            $resultCode = AuthenticationResult::FAILURE_IDENTITY_NOT_CONFIRMED;
        } else {
            $isValid = $this->tokenService->checkToken($user, $code, TokenService::TYPE_RESET_PASSWORD, true);
            if ($isValid === true) {
                try {
                    $user->setCredential($this->getAuthAdapter()->cryptCredential($newPassword));
                    $this->getAuthAdapter()->getMapper()->update($user);
                    $resultCode = AuthenticationResult::SUCCESS;
                } catch (\Exception $ex) {
                    $resultCode = AuthenticationResult::FAILURE_RECOVER_PASSWORD;
                }
            } else {
                $resultCode = AuthenticationResult::FAILURE_TOKEN_INVALID;
            }
        }

        $messages = [AuthenticationResult::MESSAGE_TEMPLATES[ $resultCode ]];

        return new AuthenticationResult($resultCode, $user, $messages);
    }

    /**
     * @param \ZfbUser\Entity\UserInterface $user
     *
     * @throws \ZfbUser\Service\Exception\TemplateNotFoundException
     * @throws \ZfbUser\Service\Exception\UnsupportedTokenTypeException
     */
    public function sendConfirmationCode(UserInterface $user)
    {
        // generate new token and revoke old confirmation tokens
        $token = $this->tokenService->generateToken($user, TokenService::TYPE_CONFIRMATION, true);

        // build confirmation url
        $url = $this->buildUrl('user/confirmation/confirm', [
            'identity' => $user->getIdentity(),
            'code'     => $token->getValue(),
        ]);

        // get mail template
        $template = $this->getMailTemplate('identity_confirmation');

        $data = [
            'confirmation_url' => $url,
        ];

        // send email
        $this->mailSender->send($user, $template, $data);
    }

    /**
     * @param \ZfbUser\Entity\UserInterface $user
     *
     * @throws \ZfbUser\Service\Exception\TemplateNotFoundException
     * @throws \ZfbUser\Service\Exception\UnsupportedTokenTypeException
     */
    public function sendRecoveryPasswordCode(UserInterface $user)
    {
        // generate new token and revoke old reset_password tokens
        $token = $this->tokenService->generateToken($user, TokenService::TYPE_RESET_PASSWORD, true);

        $url = $this->buildUrl('user/recover-password/change', [
            'identity' => $user->getIdentity(),
            'code'     => $token->getValue(),
        ]);

        $data = [
            'recover_password_url' => $url,
        ];

        $template = $this->getMailTemplate('recover_password');
        $this->mailSender->send($user, $template, $data);
    }

    /**
     * @param string $uri
     * @param array  $data
     *
     * @return string
     */
    protected function buildUrl(string $uri, array $data): string
    {
        $url = $this->moduleOptions->getBaseUrl();
        if ($url[ strlen($url) - 1 ] !== '/') {
            $url .= '/';
        }
        $url .= $uri . '?';
        $url .= http_build_query($data);

        return $url;
    }

    /**
     * @param string $name
     *
     * @return bool|string
     * @throws \ZfbUser\Service\Exception\TemplateNotFoundException
     */
    protected function getMailTemplate(string $name)
    {
        $locale = $this->getModuleOptions()->getCurrentLocale();
        $templatePath = $this->moduleOptions->getMailSenderOptions()->getTemplatePath();
        $file = $templatePath . $locale . "/{$name}.html";
        if (!file_exists($file) || !is_readable($file)) {
            throw new Exception\TemplateNotFoundException();
        }

        return file_get_contents($file);
    }
}
