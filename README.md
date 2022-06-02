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

```
terminus self:plugin:install aaronbauman/terminus-config-export
```
