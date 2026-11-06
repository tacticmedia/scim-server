<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

use TacticMedia\ScimServer\SchemaUrn;

/**
 * The schemas of RFC 7643 Figure 9 with the Verified and Held errata applied, except the "manager.$ref" part of Held
 * erratum 8359, and with Reported erratum 6011. The schemas of Figure 10 with the Verified and Held errata, with
 * Reported errata 8011 and 9018, and with authenticationSchemes.primary, so that they describe what
 * /ServiceProviderConfig returns. Also the common attributes of section 3.1.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-8.7.1
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-8.7.2
 * @see https://www.rfc-editor.org/errata/rfc7643
 */
final class CoreSchemas
{
    private const string DISPLAY = 'A human-readable name, primarily used for display purposes.';

    public static function user(): Schema
    {
        return new Schema(SchemaUrn::User->value, 'User', 'User Account', [
            new AttributeDefinition('userName', description: "Unique identifier for the User, typically used by the user to directly authenticate to the service provider. Each User MUST include a non-empty userName value. This identifier MUST be unique across the service provider's entire set of Users. REQUIRED.", required: true, uniqueness: Uniqueness::Server),
            self::name(),
            new AttributeDefinition('displayName', description: 'The name of the User, suitable for display to end-users. The name SHOULD be the full name of the User being described, if known.'),
            new AttributeDefinition('nickName', description: "The casual way to address the user in real life, e.g., 'Bob' or 'Bobby' instead of 'Robert'. This attribute SHOULD NOT be used to represent a User's username (e.g., 'bjensen' or 'mpepperidge')."),
            new AttributeDefinition('profileUrl', AttributeType::Reference, description: "A fully qualified URL pointing to a page representing the User's online profile.", caseExact: true, referenceTypes: ['external']),
            new AttributeDefinition('title', description: 'The user\'s title, such as "Vice President."'),
            new AttributeDefinition('userType', description: "Used to identify the relationship between the organization and the user. Typical values used might be 'Contractor', 'Employee', 'Intern', 'Temp', 'External', and 'Unknown', but any value may be used."),
            new AttributeDefinition('preferredLanguage', description: "Indicates the User's preferred written or spoken language. Generally used for selecting a localized user interface; e.g., 'en_US' specifies the language English and country US."),
            new AttributeDefinition('locale', description: "Used to indicate the User's default location for purposes of localizing items such as currency, date time format, or numerical representations."),
            new AttributeDefinition('timezone', description: "The User's time zone in the 'Olson' time zone database format, e.g., 'America/Los_Angeles'."),
            new AttributeDefinition('active', AttributeType::Boolean, description: "A Boolean value indicating the User's administrative status."),
            new AttributeDefinition('password', description: "The User's cleartext password. This attribute is intended to be used as a means to specify an initial password when creating a new User or to reset an existing User's password.", mutability: Mutability::WriteOnly, returned: Returned::Never),
            ...self::userContacts(),
            self::addresses(),
            self::groups(),
            ...self::userPrivileges(),
        ]);
    }

    public static function group(): Schema
    {
        return new Schema(SchemaUrn::Group->value, 'Group', 'Group', [
            // uniqueness server, unlike Figure 9: Microsoft Entra ID expects 409 for a duplicate group name.
            new AttributeDefinition('displayName', description: 'A human-readable name for the Group. REQUIRED.', required: true, uniqueness: Uniqueness::Server),
            self::complex('members', 'A list of members of the Group.', [
                // caseExact: member values are resource ids, which are caseExact, as erratum 8472 reasons for manager.value.
                new AttributeDefinition('value', description: 'Identifier of the member of this Group.', caseExact: true, mutability: Mutability::Immutable),
                new AttributeDefinition('$ref', AttributeType::Reference, description: 'The URI corresponding to a SCIM resource that is a member of this Group.', mutability: Mutability::Immutable, referenceTypes: ['User', 'Group']),
                // Erratum 6011 (Reported). Okta and Microsoft Entra ID send it.
                new AttributeDefinition('display', description: self::DISPLAY, mutability: Mutability::ReadOnly),
                new AttributeDefinition('type', description: "A label indicating the type of resource, e.g., 'User' or 'Group'.", mutability: Mutability::Immutable, canonicalValues: ['User', 'Group']),
            ]),
        ]);
    }

    public static function enterpriseUser(): Schema
    {
        return new Schema(SchemaUrn::EnterpriseUser->value, 'EnterpriseUser', 'Enterprise User', [
            new AttributeDefinition('employeeNumber', description: 'Numeric or alphanumeric identifier assigned to a person, typically based on order of hire or association with an organization.'),
            new AttributeDefinition('costCenter', description: 'Identifies the name of a cost center.'),
            new AttributeDefinition('organization', description: 'Identifies the name of an organization.'),
            new AttributeDefinition('division', description: 'Identifies the name of a division.'),
            new AttributeDefinition('department', description: 'Identifies the name of a department.'),
            self::complex('manager', "The User's manager. A complex type that optionally allows service providers to represent organizational hierarchy by referencing the 'id' attribute of another User.", [
                new AttributeDefinition('value', description: "The id of the SCIM resource representing the User's manager. REQUIRED.", required: true, caseExact: true),
                // Not required, unlike erratum 8359: Microsoft Entra ID sends only the manager id.
                new AttributeDefinition('$ref', AttributeType::Reference, description: "The URI of the SCIM resource representing the User's manager.", referenceTypes: ['User']),
                new AttributeDefinition('displayName', description: "The displayName of the User's manager. OPTIONAL and READ-ONLY.", mutability: Mutability::ReadOnly),
            ], multiValued: false),
        ]);
    }

    public static function serviceProviderConfig(): Schema
    {
        return new Schema(SchemaUrn::ServiceProviderConfig->value, 'Service Provider Configuration', "Schema for representing the service provider's configuration", [
            self::readOnly('documentationUri', "An HTTP-addressable URL pointing to the service provider's human-consumable help documentation.", AttributeType::Reference, referenceTypes: ['external']),
            self::option('patch', 'PATCH configuration options'),
            self::option('bulk', 'bulk configuration options', [
                self::readOnly('maxOperations', 'An integer value specifying the maximum number of operations.', AttributeType::Integer, required: true),
                self::readOnly('maxPayloadSize', 'An integer value specifying the maximum payload size in bytes.', AttributeType::Integer, required: true),
            ]),
            self::option('filter', 'FILTER options', [self::readOnly('maxResults', 'An integer value specifying the maximum number of resources returned in a response.', AttributeType::Integer, required: true)]),
            self::option('changePassword', 'configuration options related to changing a password'),
            self::option('sort', 'sort result options'),
            self::option('etag', 'ETag configuration options'),
            self::readOnly('authenticationSchemes', 'A complex type that specifies supported authentication scheme properties.', AttributeType::Complex, required: true, multiValued: true, subAttributes: [
                self::readOnly('type', 'The authentication scheme.', required: true, canonicalValues: ['oauth', 'oauth2', 'oauthbearertoken', 'httpbasic', 'httpdigest']),
                self::readOnly('name', 'The common authentication scheme name, e.g., HTTP Basic.', required: true),
                self::readOnly('description', 'A description of the authentication scheme.', required: true),
                self::readOnly('specUri', "An HTTP-addressable URL pointing to the authentication scheme's specification.", AttributeType::Reference, referenceTypes: ['external']),
                self::readOnly('documentationUri', "An HTTP-addressable URL pointing to the authentication scheme's usage documentation.", AttributeType::Reference, referenceTypes: ['external']),
                // Section 8.5 shows it, Figure 10 omits it; rejected erratum 7921 points to section 2.4.
                self::readOnly('primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute. The primary attribute value 'true' MUST appear no more than once.", AttributeType::Boolean),
            ]),
            self::pagination(),
        ]);
    }

    /**
     * "id" is a common attribute (erratum 8419).
     */
    public static function resourceType(): Schema
    {
        return new Schema(SchemaUrn::ResourceType->value, 'ResourceType', 'Specifies the schema that describes a SCIM resource type', [
            self::readOnly('name', "The resource type name. When applicable, service providers MUST specify the name, e.g., 'User'.", required: true, caseExact: true, uniqueness: Uniqueness::Server),
            self::readOnly('description', "The resource type's human-readable description. When applicable, service providers MUST specify the description."),
            self::readOnly('endpoint', "The resource type's HTTP-addressable endpoint relative to the Base URL, e.g., '/Users'.", AttributeType::Reference, required: true, caseExact: true, uniqueness: Uniqueness::Server, referenceTypes: ['uri']),
            self::readOnly('schema', "The resource type's primary/base schema URI.", AttributeType::Reference, required: true, caseExact: true, referenceTypes: ['uri']),
            // Optional as section 6 says, unlike Figure 10: a resource type can have no extension.
            self::readOnly('schemaExtensions', "A list of URIs of the resource type's schema extensions.", AttributeType::Complex, multiValued: true, subAttributes: [
                self::readOnly('schema', 'The URI of a schema extension.', AttributeType::Reference, required: true, caseExact: true, referenceTypes: ['uri']),
                self::readOnly('required', 'A Boolean value that specifies whether or not the schema extension is required for the resource type. If true, a resource of this type MUST include this schema extension and also include any attributes declared as required in this schema extension. If false, a resource of this type MAY omit this schema extension.', AttributeType::Boolean, required: true),
            ]),
        ]);
    }

    /**
     * "id" is a common attribute (erratum 8418).
     */
    public static function schema(): Schema
    {
        return new Schema(SchemaUrn::Schema->value, 'Schema', 'Specifies the schema that describes a SCIM schema', [
            self::readOnly('name', "The schema's human-readable name. When applicable, service providers MUST specify the name, e.g., 'User'.", required: true),
            self::readOnly('description', "The schema's human-readable description. When applicable, service providers MUST specify the description."),
            self::readOnly('attributes', 'A complex attribute that includes the attributes of a schema.', AttributeType::Complex, required: true, multiValued: true, subAttributes: [
                ...self::attributeCharacteristics(['string', 'complex', 'boolean', 'decimal', 'integer', 'dateTime', 'reference', 'binary']),
                self::readOnly('subAttributes', 'Used to define the sub-attributes of a complex attribute.', AttributeType::Complex, multiValued: true, subAttributes: self::attributeCharacteristics(['string', 'boolean', 'decimal', 'integer', 'dateTime', 'reference', 'binary'])),
            ]),
        ]);
    }

    /**
     * Part of every resource, listed in no schema. {@see ResourceType::resolve()} resolves them in the core schema.
     *
     * @see https://www.rfc-editor.org/rfc/rfc7643#section-3.1
     *
     * @return list<AttributeDefinition>
     */
    public static function commonAttributes(): array
    {
        return [
            new AttributeDefinition('id', description: 'A unique identifier for a SCIM resource as defined by the service provider.', caseExact: true, mutability: Mutability::ReadOnly, returned: Returned::Always, uniqueness: Uniqueness::Server),
            new AttributeDefinition('externalId', description: 'A String that is an identifier for the resource as defined by the provisioning client.', caseExact: true),
            self::complex('meta', 'A complex attribute containing resource metadata.', [
                new AttributeDefinition('resourceType', description: 'The name of the resource type of the resource.', caseExact: true, mutability: Mutability::ReadOnly),
                new AttributeDefinition('created', AttributeType::DateTime, description: 'The "DateTime" that the resource was added to the service provider.', mutability: Mutability::ReadOnly),
                new AttributeDefinition('lastModified', AttributeType::DateTime, description: 'The most recent DateTime that the details of this resource were updated at the service provider.', mutability: Mutability::ReadOnly),
                new AttributeDefinition('location', AttributeType::Reference, description: 'The URI of the resource being returned.', caseExact: true, mutability: Mutability::ReadOnly, referenceTypes: ['uri']),
                new AttributeDefinition('version', description: 'The version of the resource being returned.', caseExact: true, mutability: Mutability::ReadOnly),
            ], multiValued: false, mutability: Mutability::ReadOnly),
        ];
    }

    /**
     * @see https://www.rfc-editor.org/rfc/rfc9865#section-4
     */
    private static function pagination(): AttributeDefinition
    {
        return self::readOnly('pagination', 'A complex type that indicates pagination configuration options.', AttributeType::Complex, subAttributes: [
            self::readOnly('cursor', 'A Boolean value specifying support of cursor-based pagination.', AttributeType::Boolean, required: true),
            self::readOnly('index', 'A Boolean value specifying support of index-based pagination.', AttributeType::Boolean, required: true),
            self::readOnly('defaultPaginationMethod', 'A string value specifying the type of pagination that the service provider defaults to when the client has not specified which method it wishes to use. Possible values are "cursor" and "index".', canonicalValues: ['cursor', 'index']),
            self::readOnly('defaultPageSize', 'Positive integer value specifying the default number of results returned in a page when a count is not specified in the query.', AttributeType::Integer),
            self::readOnly('maxPageSize', 'Positive integer specifying the maximum number of results returned in a page regardless of what is specified for the count in a query. The maximum number of results returned may be further restricted by other criteria.', AttributeType::Integer),
            self::readOnly('cursorTimeout', 'Positive integer specifying the minimum number of seconds that a cursor is valid between page requests. Clients waiting too long between cursor pagination requests may receive an invalid cursor error response. No value being specified may mean that there is no cursor timeout or that the cursor timeout is not a static duration.', AttributeType::Integer),
        ]);
    }

    private static function name(): AttributeDefinition
    {
        return self::complex('name', "The components of the user's real name. Providers MAY return just the full name as a single string in the formatted sub-attribute, or they MAY return just the individual component attributes using the other sub-attributes, or they MAY return both. If both variants are returned, they SHOULD be describing the same name, with the formatted name indicating how the component attributes should be combined.", [
            new AttributeDefinition('formatted', description: "The full name, including all middle names, titles, and suffixes as appropriate, formatted for display (e.g., 'Ms. Barbara J Jensen, III')."),
            new AttributeDefinition('familyName', description: "The family name of the User, or last name in most Western languages (e.g., 'Jensen' given the full name 'Ms. Barbara J Jensen, III')."),
            new AttributeDefinition('givenName', description: "The given name of the User, or first name in most Western languages (e.g., 'Barbara' given the full name 'Ms. Barbara J Jensen, III')."),
            new AttributeDefinition('middleName', description: "The middle name(s) of the User (e.g., 'Jane' given the full name 'Ms. Barbara J Jensen, III')."),
            new AttributeDefinition('honorificPrefix', description: "The honorific prefix(es) of the User, or title in most Western languages (e.g., 'Ms.' given the full name 'Ms. Barbara J Jensen, III')."),
            new AttributeDefinition('honorificSuffix', description: "The honorific suffix(es) of the User, or suffix in most Western languages (e.g., 'III' given the full name 'Ms. Barbara J Jensen, III')."),
        ], multiValued: false);
    }

    /**
     * @return list<AttributeDefinition>
     */
    private static function userContacts(): array
    {
        return [
            self::plural(
                'emails',
                "Email addresses for the user. The value SHOULD be canonicalized by the service provider, e.g., 'bjensen@example.com' instead of 'bjensen@EXAMPLE.COM'. Canonical type values of 'work', 'home', and 'other'.",
                new AttributeDefinition('value', description: "Email addresses for the user. The value SHOULD be canonicalized by the service provider, e.g., 'bjensen@example.com' instead of 'bjensen@EXAMPLE.COM'. Canonical type values of 'work', 'home', and 'other'."),
                self::type("A label indicating the attribute's function, e.g., 'work' or 'home'.", ['work', 'home', 'other']),
                'the preferred mailing address or primary email address',
            ),
            self::plural(
                'phoneNumbers',
                "Phone numbers for the User. The value SHOULD be canonicalized by the service provider according to the format specified in RFC 3966, e.g., 'tel:+1-201-555-0123'. Canonical type values of 'work', 'home', 'mobile', 'fax', 'pager', and 'other'.",
                new AttributeDefinition('value', description: 'Phone number of the User.'),
                self::type("A label indicating the attribute's function, e.g., 'work', 'home', 'mobile'.", ['work', 'home', 'mobile', 'fax', 'pager', 'other']),
                'the preferred phone number or primary phone number',
            ),
            self::plural(
                'ims',
                'Instant messaging addresses for the User.',
                new AttributeDefinition('value', description: 'Instant messaging address for the User.'),
                self::type("A label indicating the attribute's function, e.g., 'aim', 'gtalk', 'xmpp'.", ['aim', 'gtalk', 'icq', 'xmpp', 'msn', 'skype', 'qq', 'yahoo']),
                'the preferred messenger or primary messenger',
            ),
            self::plural(
                'photos',
                'URLs of photos of the User.',
                new AttributeDefinition('value', AttributeType::Reference, description: 'URL of a photo of the User.', caseExact: true, referenceTypes: ['external']),
                self::type("A label indicating the attribute's function, i.e., 'photo' or 'thumbnail'.", ['photo', 'thumbnail']),
                'the preferred photo or thumbnail',
            ),
        ];
    }

    private static function addresses(): AttributeDefinition
    {
        return self::complex('addresses', "A physical mailing address for this User. Canonical type values of 'work', 'home', and 'other'. This attribute is a complex type with the following sub-attributes.", [
            new AttributeDefinition('formatted', description: 'The full mailing address, formatted for display or use with a mailing label. This attribute MAY contain newlines.'),
            new AttributeDefinition('streetAddress', description: 'The full street address component, which may include house number, street name, P.O. box, and multi-line extended street address information. This attribute MAY contain newlines.'),
            new AttributeDefinition('locality', description: 'The city or locality component.'),
            new AttributeDefinition('region', description: 'The state or region component.'),
            new AttributeDefinition('postalCode', description: 'The zip code or postal code component.'),
            new AttributeDefinition('country', description: 'The country name component.'),
            self::type("A label indicating the attribute's function, e.g., 'work' or 'home'.", ['work', 'home', 'other']),
            self::primary('the preferred mailing address'),
        ]);
    }

    private static function groups(): AttributeDefinition
    {
        return self::complex('groups', 'A list of groups to which the user belongs, either through direct membership, through nested groups, or dynamically calculated.', [
            new AttributeDefinition('value', description: "The identifier of the User's group.", mutability: Mutability::ReadOnly),
            new AttributeDefinition('$ref', AttributeType::Reference, description: "The URI of the corresponding 'Group' resource to which the user belongs.", caseExact: true, mutability: Mutability::ReadOnly, referenceTypes: ['Group']),
            new AttributeDefinition('display', description: self::DISPLAY.' READ-ONLY.', mutability: Mutability::ReadOnly),
            self::type("A label indicating the attribute's function, e.g., 'direct' or 'indirect'.", ['direct', 'indirect'], Mutability::ReadOnly),
        ], mutability: Mutability::ReadOnly);
    }

    /**
     * @return list<AttributeDefinition>
     */
    private static function userPrivileges(): array
    {
        $unlabelledType = self::type("A label indicating the attribute's function.");

        return [
            self::plural('entitlements', 'A list of entitlements for the User that represent a thing the User has.', new AttributeDefinition('value', description: 'The value of an entitlement.'), $unlabelledType),
            self::plural('roles', "A list of roles for the User that collectively represent who the User is, e.g., 'Student', 'Faculty'.", new AttributeDefinition('value', description: 'The value of a role.'), $unlabelledType),
            self::plural('x509Certificates', 'A list of certificates issued to the User.', new AttributeDefinition('value', AttributeType::Binary, description: 'The value of an X.509 certificate.'), $unlabelledType),
        ];
    }

    /**
     * A multi-valued attribute with the sub-attributes value, display, type and primary.
     */
    private static function plural(string $name, string $description, AttributeDefinition $value, AttributeDefinition $type, ?string $primaryExample = null): AttributeDefinition
    {
        return self::complex($name, $description, [$value, new AttributeDefinition('display', description: self::DISPLAY), $type, self::primary($primaryExample)]);
    }

    /**
     * @param list<AttributeDefinition> $subAttributes
     */
    private static function complex(string $name, string $description, array $subAttributes, bool $multiValued = true, Mutability $mutability = Mutability::ReadWrite): AttributeDefinition
    {
        return new AttributeDefinition($name, AttributeType::Complex, $multiValued, $description, mutability: $mutability, subAttributes: $subAttributes);
    }

    /**
     * @param list<string> $canonicalValues
     */
    private static function type(string $description, array $canonicalValues = [], Mutability $mutability = Mutability::ReadWrite): AttributeDefinition
    {
        return new AttributeDefinition('type', description: $description, mutability: $mutability, canonicalValues: $canonicalValues);
    }

    /**
     * @param list<string>              $canonicalValues
     * @param list<string>              $referenceTypes
     * @param list<AttributeDefinition> $subAttributes
     */
    private static function readOnly(
        string $name,
        string $description,
        AttributeType $type = AttributeType::String,
        bool $required = false,
        bool $caseExact = false,
        bool $multiValued = false,
        Uniqueness $uniqueness = Uniqueness::None,
        array $canonicalValues = [],
        array $referenceTypes = [],
        array $subAttributes = [],
    ): AttributeDefinition {
        return new AttributeDefinition($name, $type, $multiValued, $description, $required, $caseExact, Mutability::ReadOnly, uniqueness: $uniqueness, canonicalValues: $canonicalValues, referenceTypes: $referenceTypes, subAttributes: $subAttributes);
    }

    /**
     * A required ServiceProviderConfig option with the sub-attribute "supported".
     *
     * @param list<AttributeDefinition> $limits
     */
    private static function option(string $name, string $options, array $limits = []): AttributeDefinition
    {
        return self::readOnly($name, \sprintf('A complex type that specifies %s.', $options), AttributeType::Complex, required: true, subAttributes: [
            self::readOnly('supported', 'A Boolean value specifying whether or not the operation is supported.', AttributeType::Boolean, required: true),
            ...$limits,
        ]);
    }

    /**
     * The characteristics of an attribute in a Schema resource. Errata 5606 and 8415 set the type values.
     *
     * @param list<string> $types
     *
     * @return list<AttributeDefinition>
     */
    private static function attributeCharacteristics(array $types): array
    {
        return [
            self::readOnly('name', "The attribute's name.", required: true, caseExact: true),
            self::readOnly('type', \sprintf("The attribute's data type. Valid values include '%s'.", implode("', '", $types)), required: true, canonicalValues: $types),
            self::readOnly('multiValued', "A Boolean value indicating an attribute's plurality.", AttributeType::Boolean, required: true),
            self::readOnly('description', 'A human-readable description of the attribute.', caseExact: true),
            self::readOnly('required', 'A boolean value indicating whether or not the attribute is required.', AttributeType::Boolean),
            self::readOnly('canonicalValues', "A collection of canonical values. When applicable, service providers MUST specify the canonical types, e.g., 'work', 'home'.", caseExact: true, multiValued: true),
            self::readOnly('caseExact', 'A Boolean value indicating whether or not a string attribute is case sensitive.', AttributeType::Boolean),
            self::readOnly('mutability', 'Indicates whether or not an attribute is modifiable.', caseExact: true, canonicalValues: ['readOnly', 'readWrite', 'immutable', 'writeOnly']),
            self::readOnly('returned', 'Indicates when an attribute is returned in a response (e.g., to a query).', caseExact: true, canonicalValues: ['always', 'never', 'default', 'request']),
            self::readOnly('uniqueness', 'Indicates how unique a value must be.', caseExact: true, canonicalValues: ['none', 'server', 'global']),
            // Erratum 5607 makes the sub-attribute multi-valued too.
            self::readOnly('referenceTypes', "Used only with an attribute of type 'reference'. Specifies a SCIM resourceType that a reference attribute MAY refer to, e.g., 'User'.", caseExact: true, multiValued: true),
        ];
    }

    private static function primary(?string $example): AttributeDefinition
    {
        $description = "A Boolean value indicating the 'primary' or preferred attribute value for this attribute"
            .(null === $example ? '' : ', e.g., '.$example)
            .". The primary attribute value 'true' MUST appear no more than once.";

        return new AttributeDefinition('primary', AttributeType::Boolean, description: $description);
    }
}
