git-switch
==========

Switch your WerrrdPress theme between remote Git branches

Keep everything up to date with a cron job:

```
*/1 * * * * cd /srv/www/; wp eval 'Git_Switch()->refresh();' > /dev/null 2>&1
```

Setup `GIT_SWITCH_REPOS` constant to define the repos to switch between:

```
define( 'GIT_SWITCH_REPOS', [
    'themes/my-theme' => [
        'ssh_key_path' => 'path-to-ssh-key', // optional
    ],
    'plugins/my-plugin' => [],
] );
```

## Troubleshooting

### www-data user cannot run git command:
As root, try `visudo`, add
```
git ALL=(www-data) ALL
```
