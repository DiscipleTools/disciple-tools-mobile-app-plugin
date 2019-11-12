[![Build Status](https://travis-ci.com/DiscipleTools/disciple-tools-mobile-app-plugin.svg?branch=master)](https://travis-ci.com/DiscipleTools/disciple-tools-mobile-app-plugin)

# Disciple Tools Mobile App Plugin
The Disciple Tools Mobile App extends the Disciple Tools system to support mobile app integration.

## Team
* [Mobile App Team](https://github.com/orgs/DiscipleTools/teams/mobile-app-lead-team)

## Dependent Repo
* [Android App](https://github.com/DiscipleTools/disciple-tools-mobile-app-android)

## Setup
This plugin is bundled with the JWT plugin.

If you get errors like "Only authenticated users can access the REST API" from the app then you may need to update your .htacces
Debugging: make a POST request to`/wp-json/jwt-auth/v1/token` (with username and password post fields) to get the token.

POST `/wp-json/jwt-auth/v1/token/validate` with the token in the Authorization hearder as `Bearer {token}`. If you get this error: "Authorization header not found" then you need to update your .htaccss with:
```
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]
```
Or with:
```
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```


## Basic Design Idea
![Basic Design Idea](https://github.com/DiscipleTools/disciple-tools-mobile-app-plugin/raw/master/mobile-app-design.png)
