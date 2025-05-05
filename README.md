# Member Prime – Amazon‑Prime‑style membership for thirty bees

* Drop the folder into `modules/`, install it in the back‑office.
* Create:
  1. A **virtual product** that represents the membership (price = Y €).
  2. A **customer group** (e.g. _Member_).
  3. Either:
     * a **catalog price rule** that grants a discount _for that group_, or
     * per‑product “specific prices” restricted to the group.
* In the module configuration, fill in  
  * product ID,  
  * group ID,  
  * validity in days,  
  * the _paid_ order‑state that should grant the membership.

Add a nightly cron pointing to http(s)://your‑shop.com/modules/memberprime/cronPruneExpired.php

(or call the public method if you use the built‑in **CronJobs** module).  
The cron removes expired memberships and kicks the customer out of the group.

Enjoy! 😊
