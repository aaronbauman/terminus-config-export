# terminus-config-export
All-in-one terminus command to push config into version control

## Why do we need this?
This command wraps the sub-commands for a typical config sync -- switching to SFTP, dumping, commiting, and switching back to git -- in a single command. The best part is that each subcommand waits for the previous command to finish, to avoid convergence problems.

## Usage

`terminus cex site.env [--message="Optional commit message"]`

## Installation

`composer create-project --no-dev -d ~/.terminus/plugins aaronbauman/terminus-config-export`
