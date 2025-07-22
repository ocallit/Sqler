<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

  <xsl:output method="html" indent="yes"/>

  <xsl:template match="/">
    <html>
      <head>
        <title>PDepend Summary Report</title>
        <style>
          body { font-family: sans-serif; margin: 1em; }
          table { border-collapse: collapse; width: 100%; }
          th, td { border: 1px solid #ccc; padding: 0.5em; text-align: left; }
          th { background: #f5f5f5; }
          .ccn-high { background-color: #fdd; }
        </style>
      </head>
      <body>
        <h1>PDepend Summary Report</h1>
        <table>
          <tr>
            <th>Package</th>
            <th>Class</th>
            <th>Method</th>
            <th>CCN</th>
            <th>NPath</th>
            <th>LOC</th>
            <th>Exec LOC</th>
          </tr>
          <xsl:for-each select="*/package/class">
            <xsl:variable name="pkg" select="../@name"/>
            <xsl:variable name="cls" select="@name"/>
            <xsl:for-each select="method">
              <xsl:variable name="ccn" select="metrics/metric[@name='CyclomaticComplexity']/@value"/>
              <xsl:variable name="npath" select="metrics/metric[@name='NPathComplexity']/@value"/>
              <xsl:variable name="loc" select="metrics/metric[@name='LinesOfCode']/@value"/>
              <xsl:variable name="eloc" select="metrics/metric[@name='ExecutableLinesOfCode']/@value"/>
              <tr>
                <td><xsl:value-of select="$pkg"/></td>
                <td><xsl:value-of select="$cls"/></td>
                <td><xsl:value-of select="@name"/></td>
                <td>
                  <xsl:attribute name="class">
                    <xsl:if test="number($ccn) &gt; 10">ccn-high</xsl:if>
                  </xsl:attribute>
                  <xsl:value-of select="$ccn"/>
                </td>
                <td><xsl:value-of select="$npath"/></td>
                <td><xsl:value-of select="$loc"/></td>
                <td><xsl:value-of select="$eloc"/></td>
              </tr>
            </xsl:for-each>
          </xsl:for-each>
        </table>
      </body>
    </html>
  </xsl:template>

</xsl:stylesheet>
