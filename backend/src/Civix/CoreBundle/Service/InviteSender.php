<?php

namespace Civix\CoreBundle\Service;

use Civix\CoreBundle\Service\PushTask;
use Civix\CoreBundle\Service\EmailSender;
use Civix\CoreBundle\Entity\DeferredInvites;
use Civix\CoreBundle\Entity\Group;
use Civix\CoreBundle\Entity\User;
use Civix\CoreBundle\Entity\Invites\BaseInvite;

class InviteSender
{
    private $emailSender;
    private $templating;
    private $pushTask;
    private $entityManager;
    private $from;
    
    public function __construct(
        EmailSender $emailSender,
        PushTask $pushTask,
        \Doctrine\ORM\EntityManager $entityManager
    ) {
        $this->emailSender = $emailSender;
        $this->entityManager = $entityManager;
        $this->pushTask = $pushTask;
    }

    public function send(array $invites)
    {
        foreach ($invites as $invite) {
            if ($invite instanceof User) {
                $this->pushTask->addToQueue('sendInvitePush', array($invite->getId()));
            } elseif ($invite instanceof DeferredInvites) {
                $this->emailSender->sendInviteFromGroup($invite->getEmail(), $invite->getGroup());
            }
        }
    }

    public function sendUserInvites(array $invites)
    {
        /* @var $invite BaseInvite */
        foreach ($invites as $invite) {
            $this->pushTask->addToQueue('sendInvitePush', array($invite->getUser()->getId()));
        }
    }

    public function saveInvites(array $emails, Group $group)
    {
        $emails = array_diff(
            $emails,
            $this->entityManager->getRepository('CivixCoreBundle:DeferredInvites')
                ->getEmails($group, $emails)
        );

        $users = $this->entityManager->getRepository('CivixCoreBundle:User')->getUsersByEmails($emails);
        $usersEmails = array();
        $invites = array();

        /** @var $user \Civix\CoreBundle\Entity\User */
        foreach ($users as $user) {
            if (!$group->getInvites()->contains($user) && !$group->getUsers()->contains($user)) {
                $user->addInvite($group);
                $invites[] = $user;
                $usersEmails[] = $user->getEmail();
            }
        }

        foreach (array_diff($emails, $usersEmails) as $email) {
            $deferredInvite = $this->createDeferredInvites($email, $group);
            $this->entityManager->persist($deferredInvite);
            $invites[] = $deferredInvite;
        }

        $this->entityManager->flush();

        return $invites;
    }

    public function sendInviteForPetition($answers, Group $group)
    {
        /** @var $signedUser \Civix\CoreBundle\Entity\User */
        foreach ($answers as $signedUserAnswer) {
            $signedUser = $signedUserAnswer->getUser();
            if (!$group->getInvites()->contains($signedUser) && !$group->getUsers()->contains($signedUser)) {
                $signedUser->addInvite($group);
                if ($signedUser->getIsRegistrationComplete()) {
                    $this->pushTask->addToQueue('sendInvitePush', array($signedUser->getId()));
                } else {
                    $this->emailSender->sendInviteFromGroup($signedUser->getEmail(), $group);
                }
            }
        }
    }

    private function createDeferredInvites($email, Group $group)
    {
        $differedEntity = $this->entityManager
            ->getRepository('CivixCoreBundle:DeferredInvites')
            ->findOneBy(array(
                    'email' => $email,
                    'group' => $group
            ));
        if (!($differedEntity instanceof DeferredInvites)) {
            $differedEntity = new DeferredInvites();
            $differedEntity->setEmail($email)
                ->setGroup($group);
        }

        return $differedEntity;
    }
}
