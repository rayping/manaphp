<?php
namespace App\Admin\User\Controllers;


use App\Admin\Models\Admin;
use ManaPHP\Mvc\Controller;

class AccountController extends Controller
{
    public function registerAction()
    {
        if ($this->request->isAjax()) {
            try {
                $user_name = $this->request->get('user_name', '*|account');
                $email = $this->request->get('email', '*|email');
                $password = $this->request->get('password', '*|password');
                $code = $this->request->get('code');
            } catch (\Exception $e) {
                return $this->response->setJsonContent($e);
            }

            try {
                $this->captcha->verify($code);
            } catch (\Exception $e) {
                return $this->response->setJsonContent($e);
            }

            if (Admin::exists(['admin_name' => $user_name])) {
                return $this->response->setJsonContent('account already exists.');
            }

            if (Admin::exists(['email' => $email])) {
                return $this->response->setJsonContent('email already exists.');
            }

            $admin = new Admin();

            $admin->admin_name = $user_name;
            $admin->email = $email;
            $admin->status = Admin::STATUS_ACTIVE;
            $admin->login_ip = '';
            $admin->login_time = 0;
            $admin->salt = $this->password->salt();
            $admin->password = $this->password->hash($password, $admin->salt);

            $admin->create();

            return $this->response->setJsonContent(0);
        }
    }
}