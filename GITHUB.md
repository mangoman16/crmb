# Publish the versioned source to GitHub

Suggested repository name: **badminton-crm**, visibility **Private**.

The connected GitHub tools in this session can read the account and modify existing repositories, but do not expose repository creation. Consequently, a new remote repository has not been created by the assistant.

The release includes a local Git history bundle, `source-history.bundle`, with the `main` branch and `v0.1.0` tag. Clone it into a new local source folder:

```bash
git clone source-history.bundle badminton-crm-source
cd badminton-crm-source
git remote remove origin
```

Then either create an empty private repository in GitHub and use its exact URL:

```bash
git remote add origin https://github.com/YOUR-ACCOUNT/badminton-crm.git
git push -u origin main
git push origin v0.1.0
```

Or, on your own computer with the GitHub CLI already authenticated:

```bash
gh repo create badminton-crm --private --source=. --remote=origin --push
git push origin v0.1.0
```

The source excludes actual configuration, keys, passwords, sessions, test database contents and runtime logs. Dependencies are installed from `composer.lock`. The downloadable distribution ZIP includes dependencies for convenience; they are not committed to Git.

For a GitHub Release, select tag `v0.1.0`, use the matching changelog section, and attach the distribution ZIP. Future updates should use a new commit, changelog entry, `VERSION` value and tag. Never move an already published release tag to different code.

After creating the empty repository, share its URL in the conversation if you want the assistant to continue with the available GitHub write operations. The GitHub app may need that repository included in its allowed repositories.
