<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * อีเมล reset password ภาษาไทย (override ตัว default ของ Laravel)
 */
class ThaiResetPassword extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expire = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);

        return (new MailMessage)
            ->subject('รีเซ็ตรหัสผ่าน — Stock AI')
            ->greeting('สวัสดีครับ')
            ->line('คุณได้รับอีเมลนี้เพราะมีคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณ')
            ->action('ตั้งรหัสผ่านใหม่', $url)
            ->line("ลิงก์นี้จะหมดอายุภายใน {$expire} นาที")
            ->line('หากคุณไม่ได้เป็นผู้ขอรีเซ็ตรหัสผ่าน ไม่ต้องดำเนินการใดๆ');
    }
}
