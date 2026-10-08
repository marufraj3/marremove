<?php

namespace App\Jobs;

final class UnhideFacebookCommentJob extends AbstractFacebookCommentActionJob
{
    protected function actionName(): string
    {
        return 'unhide';
    }
}
