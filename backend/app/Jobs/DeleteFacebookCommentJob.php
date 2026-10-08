<?php

namespace App\Jobs;

final class DeleteFacebookCommentJob extends AbstractFacebookCommentActionJob
{
    protected function actionName(): string
    {
        return 'delete';
    }
}
