---
id: create-your-first-extension
title: Create Your First Extension
slug: /getting-started/create-your-first-extension/
sidebar_position: 3
---

# Create Your First Extension

Extensions are the core of your phone system --- they represent users or devices that can make and receive calls through Voxra.\
This guide walks you through setting up your **first working extension** so you can log in from a softphone or physical IP phone.

* * * * *

🧩 Step 1 -- Log In to Voxra
----------------------------

Log in to your Voxra web dashboard using your admin account.

If you've just installed Voxra, use the credentials that were generated during installation.

Once you’re logged in, **select an existing Domain** (tenant) from the Domain selector, or **create a new Domain** where the extension will be created.

* * * * *

🧭 Step 2 -- Navigate to Extensions
----------------------------------

Click on the **Extensions** tile.

This page lists all existing extensions on your domain.\
Click the **"Create"** button in the top right corner to create a new one.

* * * * *

📋 Step 3 -- Basic Extension Information
---------------------------------------

Fill out the basic details:

![Create new extension](/img/screenshots/extension-basic-info.png)

Click **Save** when done.

* * * * *


📱 Step 4 -- Connect Your Device or Softphone
--------------------------------------------

You can now connect to the PBX using any **SIP-compatible device or softphone**, such as **Zoiper**, **Groundwire**, or **Bria**.

Open your extension in Voxra and navigate to the **SIP Credentials** tab.\
Here you'll find the information needed to register your device:

-   **Domain** -- Your Voxra domain name (for example, `admin.localhost`)

-   **Username** -- Typically your extension number (e.g. `100`)

-   **SIP Password** -- Auto-generated password used for registration

Enter these values into your softphone or desk phone's SIP account settings.\
Once connected, the extension should show as **Registered** in Voxra.

![Extension SIP Credentials](/img/screenshots/extension-sip-credentials.png)

* * * * *

☑️ Step 5 -- Test Your Setup
---------------------------

Once your device registers successfully, you should see the **green "Registered"** status under the extension list.

To test:

1.  Create a second extension (e.g. `101`)

2.  Register it on another softphone

3.  Try calling between them --- both should ring and connect instantly

If the call connects, congratulations 🎉 --- your first extension is ready to use!