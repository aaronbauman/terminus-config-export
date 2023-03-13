# terminus-config-export

## `cex`
Remotely push active config into version control

### Why do we need this?
This command wraps the sub-commands for a typical on-server config sync -- switching to SFTP, dumping, commiting, and switching back to git -- in a single command. The best part is that each subcommand waits for the previous command to finish, to avoid convergence problems.

### Usage

`terminus cex site.env [--message="Optional commit message"]`


## `cexr`
Export active config from a remote environment to local

### Why	do we need this?
For a CI site, we can't do on-server commits - we need to send them to a completely different repository. Drush offers a way to export active config within the same site, or to sync staged config between two sites. The missing piece is exporting active config from one environment	to another, especially a local working directory which may not be bootstrappable. This command makes that bridge, and addresses	the common use case of more easily fetching "Config	from prod".

_NB: be	sure to	use the	`--destination`	flag if	you're not in a	working	drupal environment_

`terminus cexr site.env	[--destination="Optional local destination"]`


## Installation
Depending on which version of Terminus you have installed, install the plugin by using one of the following commands. You can see which version of Terminus you're using by executing `terminus --version`.

### Terminus 2
```
composer create-project --no-dev -d ~/.terminus/plugins aaronbauman/terminus-config-export
```

### Terminus 3
```
terminus self:plugin:install aaronbauman/terminus-config-export
```

## Under the hood
### What happens when I run `cex`?
Together with Drush, the Terminus plugin will: 
- Check for difference between active and staged config (`drush cst`).
- If there's a difference, put the target environment into SFTP mode.
- Run `drush cex` remotely to export config.
- Commit the config changes to VCS.
- Put the site back into git mode.

### What happens when I run `cexr`?
- Create a private directory in the remote environment (given by `--remote-destination` option)
- Run `drush cex` remotely to export config to the given directory.
- `rsync` the config to the local environment, as determined by `--destination` option
