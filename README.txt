INSTRUCTIONS FOR WEBFORM CIVICRM INTEGRATION

WHAT IT DOES

-Expose just about any CiviCRM contact, address, email, phone, website, activity, or custom field on a webform
-Auto-fill forms for logged in users (as contact 1).
-Auto-fill for anonymous users too if you send them a personalized link through CiviMail.
-Create or update an activity when users fill out your form.
-Open or update a case.
-Create relationships between contacts.
-Share addresses.
-Set a contact as current employer of another.
-It uses your CiviCRM default strict deduping rule to decide whether to update an existing contact or create a new one when the form is submitted by an anonymous user.
-It imposes no restrictions on how you style, rename, nest, or edit your CiviCRM fields; you may do anything with them that you can do with any other webform field.


HOW TO USE IT

-Enable the module.
-Create a new webform (or go to edit an existing one).
-Click on the CiviCRM tab.
-Drupal 6 users: installing the vertical_tabs module makes the massive civicrm options much more manageable.
 (the vertical tabs interface is already built in to Drupal 7)
-Enable the fields you like, and optionally choose introduction text and other settings.
-Your selected fields will be automatically created for you.
-Customize the webform settings for your new fields however you wish.


OPTION LISTS

Any CiviCRM field with options (whether it's a simple yes/no select, your upcoming events, or countries of the world) can be fully customized:
-First create the field on the CiviCRM tab, then visit the Webform tab and click the edit button by that field.
-You can rearrange options by dragging them up and down.
-You can disable options so they don't appear on the form.
-You can set an option to be the default value on the form.
-You can rename options.

NOTE: Once a webform field is created, the options are set and will not change automatically. So if you update an option list in CiviCRM, the corresponding webform field will not be updated unless you click the edit button as described above and enable the new options.


GROUPS AND TAGS

This module allows you to tag contacts and add them to groups when they submit the webform.

-ASSIGNING TAGS: From the Webform CiviCRM tab, check the box to enable the Tags field. Click save. Then from the Form Components tab, click the edit button for the Tags component. You may enter as many tags as you wish, separated by commas. If the tags you enter do not already exist in CiviCRM, they will be created for you.

-ADDING CONTACTS TO GROUPS: Follow the instructions as for tags. There are two options for Groups -- visible checkboxes on the webform that allow contacts to add themselves to one or more groups of your choosing, and a hidden form element that just adds them. You may use both elements if you wish, to give your users a choice about some groups, but automatically add them to others.

-OPT-IN CONFIRMATION: In the "additional options" section is a checkbox to enable confirmation emails when contacts are added to public mailing lists. It is recommended that you leave that option enabled in most situations. You may configure the text of the confirmation message using CiviCRM message templates.


CUSTOM DATA

-This module can handle (almost) any custom fields you have created for contacts, addresses, or activities. Two exceptions due to their complexity are contact references and files. Custom data for other CRM entities (cases, relationships, etc) are not currently supported.


EVENT REGISTRATION

-You can register contacts for events via webform. If your form has multiple contacts on it, you may choose to register them each separately for different events, or all together for the same event(s). If you choose to register them together, CiviCRM will show contact 1 as having registered the others.

NOTE: It is not currently possible to pay for events via webform.


STATE/PROVINCE AND COUNTRY ADDRESS FIELDS

This module gives approximately the same functionality as core CiviCRM profiles for the state field of an address:
- If you enable both state and country fields for an address, the state list will dynamically update based on the chosen country.
- If you enable a state field but not a country field for an address, only states from your site's default country will be shown.
- If the end-user has scripts disabled, the dynamic state list will degrade to a simple textbox where they may enter the abbreviation. This is why the Webform Components tab shows them as textfields.

- None of the above applies to custom fields. Custom fields of type state/province will be a non-dynamic dropdown list of all "Available states and provinces" you have enabled in CiviCRM's localization settings. This is exact same behavior as on CiviCRM profiles.


USING SPECIAL LINKS IN CIVIMAIL

CiviMail has the ability to generate links that have a unique "key" for each person it sends a message to. This module can read those keys and automatically pre-fill the webform for people who follow that link. Your constituents will thank you for not making them fill out their name, address, etc when you already know it. To send out personalized links to your form in CiviMail, simply copy and paste the url provided under "Additional Options" on the CiviCRM tab of your webform into your CiviMail message. It will look similar to this:

http://example.org/myform?cid1={contact.contact_id}&{contact.checksum}

NOTE: If you are sending an HTML (rich-text) message, the recommended method is to highlight a piece of text in your message such as "go to my form" then click the link button (at the top of the message text editor) and paste in the above-mentioned url.

The personalized links generated by CiviMail are valid for 1 week.


ABOUT THE USER MESSAGE

This feature exists to help prevent a major CRM headache: If users view your form while logged-in as someone else, or they click to your form by following someone else's personalized link (i.e. from a forwarded email), they will see that person's details on the form. Not given any alternative, they are likely to manually clear those fields and type their own information, which would cause the existing contact to be updated with a different person's details, throwing your contact data into confusion.

When enabled, users will see a configurable message instructing them to "click here" if they are not the intended contact. The link will take the appropriate action (logging them out if they are logged in, or else getting rid of the personalized hash) and bring them to an anonymous version of the form. Make sure anonymous & unknown users have access to the webform if using this feature.


CLONING A CONTACT

This is particularly useful to avoid re-doing all your webform component customizations for each contact on the form.

- Add a contact to the webform via the CiviCRM tab
- Rename, arrange, and customize all the webform fields for that contact. For now, keep them all within the auto-generated fieldset (although you may add add as many sub-fieldsets within the main one as you like, for example to contain their address fields).
- Click the clone button on the contact's fieldset.
- All fields within that fieldset (including non-civicrm fields) will be cloned.
- Note that if there are CiviCRM fields that do not belong to the contact within their fieldset (such as an activity field, or a field belonging to another contact), they will be cloned as well, which would be problematic.


RETROFITTING AN EXISTING WEBFORM

You can start recording CiviCRM contacts even for an existing webform. This falls into two scenarios:

1) You don't have any contact info fields on the form yet (name, address, etc). That's easy, just go to the CiviCRM tab of your webform, check the boxes, and the new fields will be created for you.

2) You already have contact info fields on your form. If people have already been using this form, you don't want to delete those fields because you'd lose data from all existing submissions! Instead, you can get webform_civicrm to start processing those fields by changing their field keys to the ones understood by webform_civicrm. An easy way to find the correct field key is by going to an existing civicrm-enabled webform (or create a dummy one) and copy the field key you are looking for (or see anatomy of a form key, below). Then visit the CiviCRM tab of your webform and you will see that field is enabled.


WILL CONTACTS, ACTIVITIES, ETC. BE CREATED RETROACTIVELY IF I ENABLE THIS MODULE ON AN EXISTING WEBFORM?

No. That would require some sort of batch update script, which is not part of this module.


ADVANCED USAGE - PASSING IDS IN THE URL

By default, contact 1 is assumed to be the acting user. So if you view a webform while logged-in, you will see your own contact details auto-filled on the form. You can disable that in the "additional options" so that logged in users are always presented with a blank form for entering/updating other contacts. To facilitate working with existing contacts, you can supply ids in the url. The following are supported:

cid1=123 (contact 1's ID; you can also supply cid2 and so on)

aid=456 (ID of the activity to autofill and update -- specifying an activity from a case works too)

Note that permissions are checked, so these values will be ignored if the acting user doesn't have permission to view that contact. Activity ID will be ignored if no contact is part of the given activity.


ANATOMY OF A FORM KEY - for geeks only

When this module looks to see if a particular field exists, it checks the form key. Understanding form keys can allow you to get creative with your webform elements. You can, for example, create your own webform element with a type of your choosing, give it the appropriate form key and it will be linked to that civicrm field. You can also use this to set an element to be hidden on the form, but still available as an email token.

CiviCRM webform keys all contain 6 pieces, connected by underscores. They are:
civicrm _ number _ entity _ number _ table _ field_key (note that the 6th piece may itself contain underscores)

For example, the field "civicrm_2_contact_1_address_postal_code" translates to:
civicrm_ - all civicrm fields start with this
2_contact_ - this field belongs to contact 2
1_address_ - this field is for this contact's first address (address is the table name in the database)
postal_code - the id of the field (usually the column name in the database)
So this field is for the postal code of the first address of the second contact on the form.

Note that for consistency, all form keys are treated as if everything might be multi-valued. So even though a contact can only have one first_name, the form key for contact 1's first name is still "civicrm_1_contact_1_contact_first_name" which tells us that this is a field for the first contact on the form, and the first (and only) set of contact fields for them.
