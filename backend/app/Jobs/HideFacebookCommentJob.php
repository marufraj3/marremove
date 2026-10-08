<?php

namespace App\Jobs;

final class HideFacebookCommentJob extends AbstractFacebookCommentActionJob
{
    protected function actionName(): string
    {
        return 'hide';
    }
}
