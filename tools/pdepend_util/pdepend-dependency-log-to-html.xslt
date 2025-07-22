<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
    
  <xsl:output method="html" indent="yes"/>

<xsl:template match="/">
    <html>
      <head>
        <title>PDepend Dependency Log</title>
        <style>
          body { font-family: sans-serif; margin: 1em; }
          table { border-collapse: collapse; width: 100%; margin-bottom: 2em; }
          th, td { border: 1px solid #ccc; padding: 0.5em; text-align: left; }
          th { background: #f0f0f0; }
          .warn { background-color: #fff0f0; }
        </style>
      </head>
      <body>
        <h1>Dependency Metrics (by Package)</h1>
        <table>
          <tr>
            <th>Package</th>
            <th>Ca (Afferent Coupling)</th>
            <th>Ce (Efferent Coupling)</th>
            <th>A (Abstractness)</th>
            <th>I (Instability)</th>
            <th>D (Distance)</th>
          </tr>
          <xsl:for-each select="//package">
            <xsl:variable name="d" select="@d"/>
            <tr>
              <td><xsl:value-of select="@name"/></td>
              <td><xsl:value-of select="@ca"/></td>
              <td><xsl:value-of select="@ce"/></td>
              <td><xsl:value-of select="@a"/></td>
              <td><xsl:value-of select="@i"/></td>
              <td>
                <xsl:attribute name="class">
                  <xsl:if test="number($d) &gt; 0.5">warn</xsl:if>
                </xsl:attribute>
                <xsl:value-of select="$d"/>
              </td>
            </tr>
          </xsl:for-each>
        </table>
      </body>
    </html>
  </xsl:template>

</xsl:stylesheet>
