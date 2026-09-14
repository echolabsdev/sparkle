# Installation

Getting started with Prism is a breeze.

## Requirements

Before we dive in, make sure your project meets these requirements:

- PHP 8.2 or higher
- Laravel 11.0 or higher

## Step 1: Composer Installation

::: tip
Prism is actively evolving. To prevent unexpected issues from breaking changes, we strongly recommend pinning your installation to a specific version. Example: "particle-academy/prism": "^0.3.0".
:::

First, let's add Prism to your project using Composer. Open your terminal, navigate to your project directory, and run:

```bash
composer require particle-academy/prism
```

This command will download Prism and its dependencies into your project.

## Step 2: Publish the Configuration

Prism comes with a configuration file that you'll want to customize. Publish it to your config directory by running:

```bash
php artisan vendor:publish --tag=prism-config
```

This will create a new file at `config/prism.php`. We'll explore how to configure Prism in the next section.
