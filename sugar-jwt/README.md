# SugarJwt

JWT and JWK verification for SugarCraft. This package is a skeleton: its namespace and wiring exist, and the verification classes arrive in later changes.

> Original work. There is no upstream port, so no `Mirrors charmbracelet/...` citation applies.

```sh
composer require sugarcraft/sugar-jwt
```

Requires PHP ≥ 8.3. The package has no runtime dependencies beyond PHP, and it does not depend on any other SugarCraft package.

The role of this library is to verify JSON Web Tokens and the JSON Web Key sets that sign them. The channel bundles (`candy-msteams`, and the channel-bridge validator it replaces) carry this code today in their own copies: the Teams token validator, the JWK reader with its hand-written DER handling, and the JWT validator. Those copies move here in a later step, so every bundle shares one tested verifier instead of keeping a private one.

Namespace: `SugarCraft\Jwt\`. Source lives in `src/`, and tests in `tests/`.
