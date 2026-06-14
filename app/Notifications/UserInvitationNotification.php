<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    public function __construct(public readonly Invitation $invitation)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('register.invitation', $this->invitation->token);

        return (new MailMessage)
            ->subject('調查資料檢核系統｜帳號註冊邀請')
            ->greeting('您好，')
            ->line('系統管理者邀請您註冊「調查資料檢核輔助系統」帳號。')
            ->line('請點選下方按鈕，填寫註冊表單完成帳號建立。')
            ->action('填寫註冊資料', $url)
            ->line('此邀請連結將於 '.$this->invitation->expires_at->format('Y-m-d H:i').' 失效。')
            ->line('若您並未預期收到此邀請，請忽略本信件。');
    }
}
