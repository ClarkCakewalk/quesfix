<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly int $validMinutes,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('調查資料檢核系統｜登入驗證碼')
            ->greeting('您好，')
            ->line('系統偵測到您正在以新的裝置登入（或此裝置距上次驗證已超過 90 天），請輸入以下驗證碼完成登入：')
            ->line('**'.$this->code.'**')
            ->line("此驗證碼將於 {$this->validMinutes} 分鐘後失效。")
            ->line('若這不是您本人的登入，請盡速變更密碼並通知系統管理者。')
            ->salutation('調查資料檢核系統');
    }
}
