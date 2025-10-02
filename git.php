<?php

namespace Deployer;

require_once __DIR__. '/env.php';

$gitUser = get('git_user', envGet('GIT_USER'));
$gitPass = get('git_password', envGet('GIT_PASS'));
$gitRepo = get('git_repo', envGet('GIT_REPO'));
$gitRepoUser = get('git_repo_path', envGet('GIT_REPO_PATH', 'dev-mugonat'));
$gitBranch = get('git_repo_branch', envGet('GIT_BRANCH', 'main'));
$gitDomain = get('git_domain', envGet('GIT_DOMAIN', 'gitlab.com'));

set('repository', "https://$gitUser:$gitPass@$gitDomain/$gitRepoUser/$gitRepo.git");
set('branch', $gitBranch);