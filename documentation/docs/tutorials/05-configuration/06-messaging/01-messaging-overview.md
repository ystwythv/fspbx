---
id: messaging-overview
title: Messaging Overview
slug: /configuration/messaging/overview
sidebar_position: 1
---

Messaging Overview
==================

Voxra includes a modern, flexible messaging framework that allows your system to **send and receive both SMS and MMS messages** using multiple supported providers. This article provides a high-level overview of how messaging works within Voxra. Provider-specific setup guides are covered in separate articles.

* * * * *

Key Capabilities
----------------

### **Send & Receive SMS and MMS Through Multiple Providers**

Voxra supports several messaging carriers and gives you the freedom to choose which provider handles each phone number.  
Once configured, any messaging-capable number can:

-   Receive inbound SMS and MMS messages

-   Send outbound SMS and MMS replies

-   Deliver messages to the Voxra dashboard

-   Optionally deliver messages to the mobile app

-   Optionally send notifications via email (read-only)

You can mix and match providers — each phone number can use a different provider if needed.

* * * * *

Messaging in the Voxra Dashboard
---------------------------------

Messaging is now built directly into the **Voxra dashboard**.  
Users can view conversations, read inbound messages, and send replies without needing the mobile app.

This allows Voxra users to manage business text messaging directly from the web interface, making messaging more accessible for desktop users and teams working inside the PBX dashboard.

* * * * *

Mobile App Integration
----------------------

Voxra can still deliver messages to the **mobile app**, but the mobile app is no longer required for messaging.

When a phone number is assigned to an extension:

1.  Inbound messages can be delivered to that extension

2.  The user can receive them in the mobile app

3.  The user can also reply from the app if desired

This remains a useful option for users who want to stay connected while away from their desk.

* * * * *

Email Notifications (Optional)
------------------------------

Voxra can also send message notifications to a user's **email address** for convenience.  
However:

-   Email delivery is *one-way only*

-   Users **cannot reply from email** to send a message back

-   Replies must be made from the Voxra dashboard or the mobile app

* * * * *

Enabling Messaging on a Phone Number
------------------------------------

Before a number can send or receive messages, it must be configured in:

**Advanced → Message Settings**

For each phone number, you must set:

### **1\. Messaging Provider**

Choose which carrier (for example Bandwidth, Sinch, Commio, Telnyx, ClickSend, and others) will handle messaging for this number.

### **2\. Destination**

Assign where inbound messages should be delivered:

-   An **extension**

    -   Allows the user to work with messages inside the Voxra dashboard
    -   Can also deliver messages to the mobile app if the user has one

-   An **email address**

    -   Sends a read-only copy of the message to email

You can assign either or both.

Once configured, Voxra automatically routes:

-   Inbound SMS/MMS → to the selected extension and/or email

-   Outbound replies → back through the correct provider to the original sender

* * * * *

MMS Support
-----------

Voxra now supports **MMS messaging**, allowing users to send and receive picture messages in addition to regular text messages.

This means conversations are no longer limited to plain SMS. Users can exchange media with customers directly through supported providers and manage those conversations from the Voxra dashboard or mobile app.

Some MMS features may depend on provider support and system storage configuration.

* * * * *

System Notification SMS Numbers
-------------------------------

Voxra also allows adding **system notification numbers** under Message Settings.

These numbers are used specifically for **system-generated messages**, such as:

-   Voicemail delivery notifications

-   Optional voicemail transcription summaries

A separate article covers SMS voicemail notifications in more detail.

These numbers are **not** attached to an extension and are not used for normal two-way user conversations.

* * * * *

Logs
----

Detailed message logs can be viewed in:

**Status → Logs → Messages**


* * * * *

Summary
-------

Voxra provides a powerful, flexible messaging engine with:

-   Multi-provider support

-   SMS and MMS support

-   Two-way messaging directly in the Voxra dashboard

-   Optional mobile app delivery

-   Optional email notifications

-   Provider and routing configuration per number

-   Support for system-level notification numbers

Once a number is messaging-enabled and assigned to a provider and destination, Voxra handles the full messaging lifecycle: receiving inbound messages, delivering them to users, and sending outbound replies through the correct provider.